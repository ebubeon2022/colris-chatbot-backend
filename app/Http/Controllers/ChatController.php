<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function respond(Request $request)
    {
        $userMessage = $request->input('message');
        $sessionId = $request->input('session_id') ?? (string) Str::uuid();
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        DB::table('conversations')->insert([
            'session_id' => $sessionId,
            'user_id' => $user->id,
            'sender' => 'user',
            'message' => $userMessage,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Load library settings
        $settings = DB::table('library_settings')->get();
        $libraryInfo = [];
        foreach ($settings as $setting) {
            $libraryInfo[$setting->key] = $setting->value;
        }

        // Load active knowledge base
        $knowledge = DB::table('knowledge_base')
            ->where('active', true)
            ->orderBy('category')
            ->get();

        // Build knowledge base string
        $knowledgeString = '';
        if ($knowledge->count() > 0) {
            $grouped = [];
            foreach ($knowledge as $item) {
                $grouped[$item->category][] = $item;
            }
            foreach ($grouped as $category => $items) {
                $knowledgeString .= "\n\n" . strtoupper($category) . ":\n";
                foreach ($items as $item) {
                    if ($item->question) {
                        $knowledgeString .= "Q: " . $item->question . "\nA: " . $item->answer . "\n";
                    } else {
                        $knowledgeString .= "- " . $item->answer . "\n";
                    }
                }
            }
        }

        // AI personality settings
        $aiName = $libraryInfo['ai_name'] ?? 'COLRIS Library Assistant';
        $aiTone = $libraryInfo['ai_tone'] ?? 'professional and helpful';
        $aiRestrictions = $libraryInfo['ai_restrictions'] ?? '';

        // Generate COLRIS search URL
        $colrisUrl = $this->generateColrisUrl($userMessage);

        // Book search
        $bookResults = '';
        $bookKeywords = ['recommend', 'book', 'books', 'read', 'resources', 'material', 'textbook', 'literature', 'suggest', 'find book', 'link', 'links', 'url', 'website', 'access'];
        $isBookQuery = false;
        foreach ($bookKeywords as $keyword) {
            if (stripos($userMessage, $keyword) !== false) {
                $isBookQuery = true;
                break;
            }
        }
        if ($isBookQuery) {
            $bookResults = $this->searchOpenLibrary($userMessage);
        }

        // Load physical books from database
        $physicalBooks = DB::table('books')->where('stock', '>', 0)->get();
        $physicalBooksString = '';
        if ($physicalBooks->count() > 0) {
            $physicalBooksString = "\n\nPHYSICAL BOOKS AVAILABLE IN THE LIBRARY:\n";
            foreach ($physicalBooks as $book) {
                $physicalBooksString .= '- ' . $book->title . ' by ' . $book->author;
                if ($book->category) $physicalBooksString .= ' (' . $book->category . ')';
                if ($book->location) $physicalBooksString .= ' — Location: ' . $book->location;
                $physicalBooksString .= ' — Stock: ' . $book->stock . " copies\n";
            }
        }

        if ($isAdmin) {
            $systemPrompt = "You are " . $aiName . ", an intelligent library assistant for Covenant University COLRIS library system.
Your tone is " . $aiTone . ".
You are currently speaking with a LIBRARY STAFF MEMBER / ADMIN. Give them full detailed information including internal data.

LIBRARY HOURS:
- Monday to Friday: " . ($libraryInfo['hours_weekday'] ?? '8:00 AM - 8:00 PM') . "
- Saturday: " . ($libraryInfo['hours_saturday'] ?? '10:00 AM - 8:00 PM') . "
- Sunday: " . ($libraryInfo['hours_sunday'] ?? '12:00 PM - 8:00 PM') . "

BORROWING POLICY:
- Borrowing limit: " . ($libraryInfo['borrowing_limit'] ?? '5 books for 2 weeks') . "
- Fine for late return: " . ($libraryInfo['fine_per_day'] ?? 'N50 per day') . "

CONTACT:
- Email: " . ($libraryInfo['contact_email'] ?? 'library@covenantuniversity.edu.ng') . "
- Phone: " . ($libraryInfo['contact_phone'] ?? '+234-1-791-3000') . "

ANNOUNCEMENT: " . ($libraryInfo['library_announcement'] ?? '') . "

COLRIS CATALOGUE LINK: " . $colrisUrl . "
Always provide this link when users ask about books, resources, or the library catalogue.

ADMIN-ONLY INFORMATION:
- You can discuss book stock levels, borrowing records, overdue books and fines
- You can share internal library statistics and management information
- You can help with staff workflows, cataloguing and library management tasks
- Always remind staff they can update settings in the Admin Panel

You are speaking with: " . $user->name . " (Staff/Admin)";
        } else {
            $systemPrompt = "You are " . $aiName . ", an intelligent library assistant for Covenant University COLRIS library system.
Your tone is " . $aiTone . ".
You are currently speaking with a STUDENT. Only share student-appropriate information.

LIBRARY HOURS:
- Monday to Friday: " . ($libraryInfo['hours_weekday'] ?? '8:00 AM - 8:00 PM') . "
- Saturday: " . ($libraryInfo['hours_saturday'] ?? '10:00 AM - 8:00 PM') . "
- Sunday: " . ($libraryInfo['hours_sunday'] ?? '12:00 PM - 8:00 PM') . "

BORROWING POLICY:
- Borrowing limit: " . ($libraryInfo['borrowing_limit'] ?? '5 books for 2 weeks') . "
- Fine for late return: " . ($libraryInfo['fine_per_day'] ?? 'N50 per day') . "

CONTACT:
- Email: " . ($libraryInfo['contact_email'] ?? 'library@covenantuniversity.edu.ng') . "
- Phone: " . ($libraryInfo['contact_phone'] ?? '+234-1-791-3000') . "

ANNOUNCEMENT: " . ($libraryInfo['library_announcement'] ?? '') . "

COLRIS CATALOGUE LINK: " . $colrisUrl . "
IMPORTANT: Always provide this link when students ask about books, resources, or want to search the library catalogue. Never say you cannot provide links.

STUDENT RESTRICTIONS:
- Do NOT share other students personal borrowing records
- Do NOT share internal stock management details
- Do NOT share staff-only information
- Focus on helping the student find books, understand policies and use library resources

You are speaking with: " . $user->name . " (Student)";
        }

        // Add knowledge base
        if ($knowledgeString) {
            $systemPrompt .= "\n\nLIBRARY KNOWLEDGE BASE (use this information to answer questions accurately):\n" . $knowledgeString;
        }

        // Add physical books
        if ($physicalBooksString) {
            $systemPrompt .= $physicalBooksString;
        }

        // Add restrictions
        if ($aiRestrictions) {
            $systemPrompt .= "\n\nTOPICS TO AVOID OR RESTRICTIONS:\n" . $aiRestrictions;
        }

        // Add open library results
        if ($bookResults) {
            $systemPrompt .= "\n\nAdditional books from Open Library for this query:\n" . $bookResults;
        }

        // Load conversation history
        $history = DB::table('conversations')
            ->where('session_id', $sessionId)
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'asc')
            ->get();

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($history as $msg) {
            $role = $msg->sender === 'user' ? 'user' : 'assistant';
            $messages[] = ['role' => $role, 'content' => $msg->message];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('GROQ_API_KEY'),
            'Content-Type' => 'application/json',
        ])->timeout(30)->post('https://api.groq.com/openai/v1/chat/completions', [
            'model' => 'llama-3.3-70b-versatile',
            'messages' => $messages,
            'max_tokens' => 1024,
        ]);

        $data = $response->json();
        $botReply = $data['choices'][0]['message']['content'] ?? 'Sorry, I could not process your request. Please try again.';

        DB::table('conversations')->insert([
            'session_id' => $sessionId,
            'user_id' => $user->id,
            'sender' => 'bot',
            'message' => $botReply,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'reply' => $botReply,
            'session_id' => $sessionId
        ]);
    }

    private function generateColrisUrl($query)
    {
        // Remove common filler words to extract just the search topic
        $fillers = [
            'find me', 'find', 'i want', 'i need', 'give me', 'show me',
            'search for', 'look for', 'can you', 'please', 'help me',
            'recommend', 'suggest', 'what books', 'any books', 'books on',
            'books about', 'a book on', 'a book about', 'an introduction to',
            'introduction to', 'resources on', 'materials on', 'book',
            'books', 'link', 'links', 'the', 'a', 'an',
        ];

        $clean = strtolower(trim($query));
        foreach ($fillers as $filler) {
            $clean = preg_replace('/\b' . preg_quote($filler, '/') . '\b/i', '', $clean);
        }
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        $encoded = rawurlencode($clean);
        return 'https://colris.covenantuniversity.edu.ng/discovery/search?query=any,contains,' . $encoded . '&tab=Everything&search_scope=MyInst_and_CI&vid=234COU_INST:VU1&lang=en&offset=0';
    }

    private function searchOpenLibrary($query)
    {
        try {
            $searchQuery = urlencode($query);
            $response = Http::get("https://openlibrary.org/search.json?q={$searchQuery}&limit=5&fields=title,author_name,first_publish_year,key,isbn");
            if (!$response->successful()) return '';
            $data = $response->json();
            if (empty($data['docs'])) return '';
            $books = [];
            foreach ($data['docs'] as $book) {
                $title = $book['title'] ?? 'Unknown Title';
                $author = isset($book['author_name']) ? implode(', ', array_slice($book['author_name'], 0, 2)) : 'Unknown Author';
                $year = $book['first_publish_year'] ?? 'N/A';
                $key = $book['key'] ?? '';
                $link = $key ? "https://openlibrary.org{$key}" : '';
                $books[] = "Title: {$title} | Author: {$author} | Year: {$year} | Link: {$link}";
            }
            return implode("\n", $books);
        } catch (\Exception $e) {
            return '';
        }
    }

    public function history(Request $request)
    {
        $user = $request->user();
        $sessions = DB::table('conversations')
            ->select('session_id', DB::raw('MIN(created_at) as started_at'),
                     DB::raw('COUNT(*) as message_count'),
                     DB::raw('MIN(CASE WHEN sender = "user" THEN message END) as first_message'))
            ->where('user_id', $user->id)
            ->groupBy('session_id')
            ->orderBy('started_at', 'desc')
            ->limit(10)
            ->get();
        return response()->json(['history' => $sessions]);
    }

    public function getSession($sessionId)
    {
        $messages = DB::table('conversations')
            ->where('session_id', $sessionId)
            ->orderBy('created_at', 'asc')
            ->get();
        return response()->json(['messages' => $messages]);
    }
}
