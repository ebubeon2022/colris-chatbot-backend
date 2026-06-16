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

        // Generate COLRIS search URL - only for book searches, not for specific resource types
        // Detect specific resource types and generate appropriate COLRIS URL
        $msgLower = strtolower($userMessage);
        $colrisUrl = '';

        if (str_contains($msgLower, 'journal') || str_contains($msgLower, 'article') || str_contains($msgLower, 'paper')) {
            $topic = $this->extractTopic($userMessage, ['journal', 'journals', 'article', 'articles', 'paper', 'papers', 'find', 'need', 'get', 'me', 'some', 'academic', 'i']);
            $colrisUrl = 'https://colris.covenantuniversity.edu.ng/discovery/jsearch?' . ($topic ? 'query=any,contains,' . urlencode($topic) . '&' : '') . 'vid=234COU_INST:VU1&lang=en';
        } elseif (str_contains($msgLower, 'newspaper') || str_contains($msgLower, 'news')) {
            $topic = $this->extractTopic($userMessage, ['newspaper', 'newspapers', 'news', 'find', 'need', 'get', 'me', 'some', 'i']);
            $colrisUrl = 'https://colris.covenantuniversity.edu.ng/discovery/npsearch?' . ($topic ? 'query=any,contains,' . urlencode($topic) . '&' : '') . 'vid=234COU_INST:VU1&lang=en';
        } elseif (str_contains($msgLower, 'database') || str_contains($msgLower, 'e-resource') || str_contains($msgLower, 'eresource')) {
            $colrisUrl = 'https://colris.covenantuniversity.edu.ng/discovery/dbsearch?vid=234COU_INST:VU1&lang=en';
        } elseif (str_contains($msgLower, 'browse') || str_contains($msgLower, 'subject') || str_contains($msgLower, 'author')) {
            $colrisUrl = 'https://colris.covenantuniversity.edu.ng/discovery/browse?vid=234COU_INST:VU1&lang=en';
        } elseif (str_contains($msgLower, 'new arrival') || str_contains($msgLower, 'latest book') || str_contains($msgLower, 'recently added')) {
            $colrisUrl = 'https://clr.covenantuniversity.edu.ng/';
        } elseif (str_contains($msgLower, 'curated') || str_contains($msgLower, 'collection')) {
            $colrisUrl = 'https://collections.clr.covenantuniversity.edu.ng/';
        } else {
            $colrisUrl = $this->generateColrisUrl($userMessage);
        }

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

COLRIS CATALOGUE SEARCH: When a user asks for a book or resource, generate a real working link. Example for data structures:
https://colris.covenantuniversity.edu.ng/discovery/search?query=any,contains,data+structures&tab=Everything&search_scope=MyInst_and_CI&vid=234COU_INST:VU1&lang=en&offset=0
NEVER use the word TOPIC literally in the link. Always substitute the real keyword from the user question.

ADMIN-ONLY INFORMATION:
- You can discuss book stock levels, borrowing records, overdue books and fines
- You can share internal library statistics and management information
- You can help with staff workflows, cataloguing and library management tasks
- Always remind staff they can update settings in the Admin Panel

INSTRUCTIONS — follow these tiers:
TIER 1 — LIBRARY/ADMIN QUESTIONS: Answer fully with internal data and COLRIS links where relevant.
TIER 2 — GENERAL KNOWLEDGE QUESTIONS: Answer directly and helpfully like a knowledgeable academic assistant. Do not restrict yourself to library topics only.
TIER 3 — QUESTIONS YOU CANNOT ANSWER: Politely explain and suggest the appropriate resource.

COLRIS INTEGRATION RULES - use EXACTLY these URLs, never generate your own:
1. BOOKS: User asks to find or search a book - use the pre-generated link above
2. JOURNALS: User mentions journal, article, academic paper - ALWAYS use exactly: https://colris.covenantuniversity.edu.ng/discovery/jsearch?vid=234COU_INST:VU1&lang=en
3. NEWSPAPERS: User mentions newspaper or news - ALWAYS use exactly: https://colris.covenantuniversity.edu.ng/discovery/npsearch?vid=234COU_INST:VU1&lang=en
4. DATABASES: User mentions database or e-resource - ALWAYS use exactly: https://colris.covenantuniversity.edu.ng/discovery/dbsearch?vid=234COU_INST:VU1&lang=en
5. BROWSE: User wants to browse by subject or author - ALWAYS use exactly: https://colris.covenantuniversity.edu.ng/discovery/browse?vid=234COU_INST:VU1&lang=en
6. NEW ARRIVALS: User asks about new or latest books - ALWAYS use exactly: https://clr.covenantuniversity.edu.ng/
7. CURATED COLLECTIONS: User asks about curated or recommended resources - ALWAYS use exactly: https://collections.clr.covenantuniversity.edu.ng/
8. FETCH ITEM: User has a specific citation to locate - ALWAYS use exactly: https://colris.covenantuniversity.edu.ng/discovery/citationlinker?vid=234COU_INST:VU1&lang=en
9. RESERVATIONS: User wants to borrow or reserve - direct to https://colris.covenantuniversity.edu.ng and tell them to log in with student ID
IMPORTANT: Never use the generic search URL for journals, newspapers, databases or browsing. Always use the specific URL for each type.

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

COLRIS CATALOGUE SEARCH: When a user asks for a book or resource, generate a real working link. Example for machine learning:
https://colris.covenantuniversity.edu.ng/discovery/search?query=any,contains,machine+learning&tab=Everything&search_scope=MyInst_and_CI&vid=234COU_INST:VU1&lang=en&offset=0
NEVER use the word TOPIC literally in the link. Always substitute the real keyword from the user question.

COLRIS INTEGRATION RULES - use EXACTLY these URLs, never generate your own:
1. BOOKS: User asks to find or search a book - use the pre-generated link above
2. JOURNALS: User mentions journal, article, academic paper - ALWAYS use exactly: https://colris.covenantuniversity.edu.ng/discovery/jsearch?vid=234COU_INST:VU1&lang=en
3. NEWSPAPERS: User mentions newspaper or news - ALWAYS use exactly: https://colris.covenantuniversity.edu.ng/discovery/npsearch?vid=234COU_INST:VU1&lang=en
4. DATABASES: User mentions database or e-resource - ALWAYS use exactly: https://colris.covenantuniversity.edu.ng/discovery/dbsearch?vid=234COU_INST:VU1&lang=en
5. BROWSE: User wants to browse by subject or author - ALWAYS use exactly: https://colris.covenantuniversity.edu.ng/discovery/browse?vid=234COU_INST:VU1&lang=en
6. NEW ARRIVALS: User asks about new or latest books - ALWAYS use exactly: https://clr.covenantuniversity.edu.ng/
7. CURATED COLLECTIONS: User asks about curated or recommended resources - ALWAYS use exactly: https://collections.clr.covenantuniversity.edu.ng/
8. FETCH ITEM: User has a specific citation to locate - ALWAYS use exactly: https://colris.covenantuniversity.edu.ng/discovery/citationlinker?vid=234COU_INST:VU1&lang=en
9. RESERVATIONS: User wants to borrow or reserve - direct to https://colris.covenantuniversity.edu.ng and tell them to log in with student ID
IMPORTANT: Never use the generic search URL for journals, newspapers, databases or browsing. Always use the specific URL for each type.

INSTRUCTIONS — follow these tiers strictly:

RESPONSE LENGTH RULE: Keep ALL responses concise — maximum 3 short paragraphs. Do not pad responses with unnecessary suggestions or repeated information. Get to the point quickly.

ABOUT THIS SYSTEM: If a user asks what you can do, what the app does, or how to use it, give this response:
I am the COLRIS Library AI Assistant for Covenant University. Here is what I can help you with:
- Find books and resources in the COLRIS catalogue with direct search links
- Answer questions about library hours, borrowing limits, fines and policies
- Generate academic citations in APA, MLA or Harvard format
- Submit book requests to the librarian if a book is not available
- Guide you to academic databases like JSTOR, IEEE Xplore and Google Scholar
- Answer general knowledge and academic questions
- Provide subject guides for your research topics
Use the sidebar on the left to access all these features directly.

TIER 1 — LIBRARY QUESTIONS (about books, COLRIS, borrowing, hours, fines, databases, library facilities):
- Answer fully using the library knowledge base and settings above
- When the student asks to find, search, or locate a book or resource, use EXACTLY this pre-generated COLRIS link (do not modify it): " . $colrisUrl . "
- For general library questions (hours, fines, borrowing rules, policies) answer directly WITHOUT a COLRIS link
- When mentioning any book title, hyperlink it using: https://colris.covenantuniversity.edu.ng/discovery/search?query=any,contains,BOOKTITLE&tab=Everything&search_scope=MyInst_and_CI&vid=234COU_INST:VU1&lang=en&offset=0
- Do NOT share other students personal information or internal stock details

SMART BOOK RECOMMENDATION: When a student explicitly asks for book recommendations or resources on a topic, recommend 2-3 relevant books from the physical books list above if available, plus ONE COLRIS search link. Do NOT add COLRIS links when the student is just mentioning a topic in passing.

SUBJECT GUIDE: When a student says they are studying, writing a project, or researching a topic, provide a structured subject guide with:
1. Recommended COLRIS search keywords for that topic
2. Relevant databases (JSTOR, IEEE Xplore, Google Scholar, PubMed depending on subject)
3. 2-3 recommended book titles from the physical catalogue if available
4. One citation tip for the subject area

LIBRARY SEAT AND RESOURCE AVAILABILITY: When a student asks about computer labs, study rooms, printers, wifi, or library facilities availability, check the knowledge base for current status set by the admin. If not in knowledge base, provide the library contact details and suggest they call ahead.

TIER 2 — GENERAL KNOWLEDGE QUESTIONS (history, science, geography, current affairs, definitions, Covenant University info, academic concepts):
- Answer directly and helpfully like a knowledgeable academic assistant
- Do NOT provide COLRIS links for general knowledge questions
- Do NOT say you can only help with library matters
- Keep answers concise, accurate, and academically appropriate
- Examples: who invented the telephone, what is machine learning, who is the VC of Covenant University, what is the capital of France, explain photosynthesis

TIER 3 — QUESTIONS YOU CANNOT ANSWER (personal advice, medical diagnosis, legal advice, requests for harmful content, things requiring real-time data you don't have):
- Politely explain you cannot help with that specific request
- Suggest they speak to the appropriate person or service
- Do NOT attempt to answer if you genuinely don't know

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

        // Detect fallback: only trigger handoff for truly unanswerable queries
        // General knowledge questions should NOT trigger the handoff card
        $generalKnowledgeKeywords = [
            'who', 'what', 'when', 'where', 'why', 'how', 'which', 'define',
            'explain', 'tell me', 'describe', 'meaning', 'history', 'capital',
            'president', 'founder', 'invented', 'discovered', 'wrote', 'author',
            'country', 'continent', 'science', 'biology', 'chemistry', 'physics',
            'math', 'geography', 'economics', 'language', 'culture', 'university',
            'covenant', 'vc', 'vice chancellor', 'professor', 'department', 'college',
            'nigeria', 'africa', 'world', 'theory', 'concept', 'algorithm',
        ];
        $libraryKeywords = [
            'book', 'books', 'borrow', 'borrowing', 'library', 'hours', 'open',
            'fine', 'fines', 'return', 'renew', 'catalogue', 'colris', 'journal',
            'database', 'resource', 'search', 'access', 'loan', 'card', 'account',
            'staff', 'librarian', 'reading', 'study', 'research', 'reference',
            'floor', 'section', 'computer', 'print', 'copy', 'photocopy', 'wifi',
            'internet', 'e-book', 'ebook', 'thesis', 'project', 'assignment',
            'reserve', 'reservation', 'overdue', 'penalty', 'lost', 'damage',
            'membership', 'register', 'id', 'student', 'academic', 'title',
            'author', 'publisher', 'isbn', 'subject', 'topic', 'find', 'locate',
        ];
        $isFallback = true;
        $queryLower = strtolower($userMessage);
        // Not a fallback if it's a library question
        foreach ($libraryKeywords as $keyword) {
            if (str_contains($queryLower, $keyword)) {
                $isFallback = false;
                break;
            }
        }
        // Also not a fallback if it's a general knowledge question
        if ($isFallback) {
            foreach ($generalKnowledgeKeywords as $keyword) {
                if (str_contains($queryLower, $keyword)) {
                    $isFallback = false;
                    break;
                }
            }
        }
        // Check AI response for explicit fallback phrases
        $fallbackPhrases = [
            "i don't understand", "i'm not sure", "i cannot help",
            "could you rephrase", "please rephrase", "not able to help",
            "outside my knowledge", "i'm unable to", "beyond my ability",
        ];
        $replyLower = strtolower($botReply);
        foreach ($fallbackPhrases as $phrase) {
            if (str_contains($replyLower, $phrase)) {
                $isFallback = true;
                break;
            }
        }

        return response()->json([
            'reply' => $botReply,
            'session_id' => $sessionId,
            'is_fallback' => $isFallback,
        ]);
    }

    private function extractTopic($query, $removeWords)
    {
        $clean = strtolower(trim($query));
        foreach ($removeWords as $word) {
            $clean = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $clean);
        }
        $clean = trim(preg_replace('/\s+/', ' ', $clean));
        return strlen($clean) > 2 ? $clean : '';
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

    public function adminFeedback(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }

        $feedback = DB::table('conversations')
            ->join('users', 'conversations.user_id', '=', 'users.id')
            ->select('conversations.id', 'conversations.message', 'conversations.feedback', 'conversations.created_at', 'users.name as user_name', 'users.email as user_email')
            ->whereNotNull('conversations.feedback')
            ->where('conversations.sender', 'bot')
            ->orderBy('conversations.created_at', 'desc')
            ->limit(100)
            ->get();

        return response()->json(['feedback' => $feedback]);
    }

    public function feedback(Request $request)
    {
        $request->validate([
            'session_id' => 'required|string',
            'message_index' => 'required|integer',
            'feedback' => 'required|string|in:up,down',
        ]);

        $user = $request->user();

        $messages = DB::table('conversations')
            ->where('user_id', $user->id)
            ->where('session_id', $request->session_id)
            ->orderBy('created_at', 'asc')
            ->get();

        if (isset($messages[$request->message_index])) {
            DB::table('conversations')
                ->where('id', $messages[$request->message_index]->id)
                ->update(['feedback' => $request->feedback]);
        }

        return response()->json(['message' => 'Feedback recorded.']);
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
