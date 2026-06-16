<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    private function checkAdmin(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Access denied. Admin only.');
        }
    }

    public function getDashboard(Request $request)
    {
        $this->checkAdmin($request);
        $totalUsers = DB::table('users')->count();
        $totalStudents = DB::table('users')->where('role', 'student')->count();
        $totalMessages = DB::table('conversations')->count();
        $totalSessions = DB::table('conversations')->distinct('session_id')->count('session_id');
        $totalBooks = DB::table('books')->count();
        $newArrivals = DB::table('books')->where('is_new_arrival', true)->count();
        $knowledgeEntries = DB::table('knowledge_base')->where('active', true)->count();
        $messagesToday = DB::table('conversations')->whereDate('created_at', today())->count();
        $recentSessions = DB::table('conversations')
            ->leftJoin('users', 'conversations.user_id', '=', 'users.id')
            ->select('conversations.session_id', 'users.name as user_name',
                DB::raw('MIN(conversations.created_at) as started_at'),
                DB::raw('MIN(CASE WHEN conversations.sender = "user" THEN conversations.message END) as first_message'))
            ->groupBy('conversations.session_id', 'users.name')
            ->orderBy('started_at', 'desc')
            ->limit(5)
            ->get();
        return response()->json([
            'total_users' => $totalUsers,
            'total_students' => $totalStudents,
            'total_messages' => $totalMessages,
            'total_sessions' => $totalSessions,
            'total_books' => $totalBooks,
            'total_book_requests' => DB::table('book_requests')->count(),
            'new_arrivals' => $newArrivals,
            'knowledge_entries' => $knowledgeEntries,
            'messages_today' => $messagesToday,
            'recent_sessions' => $recentSessions,
        ]);
    }

    public function deleteOldLogs(Request $request)
    {
        $this->checkAdmin($request);
        $days = $request->input('days', 30);
        $deleted = DB::table('conversations')->where('created_at', '<', now()->subDays($days))->delete();
        return response()->json([
            'message' => 'Deleted ' . $deleted . ' messages older than ' . $days . ' days.',
            'deleted' => $deleted,
        ]);
    }

    public function getSettings(Request $request)
    {
        $this->checkAdmin($request);
        $settings = DB::table('library_settings')->get();
        return response()->json(['settings' => $settings]);
    }

    public function updateSetting(Request $request, $key)
    {
        $this->checkAdmin($request);
        DB::table('library_settings')->where('key', $key)->update(['value' => $request->input('value'), 'updated_at' => now()]);
        return response()->json(['message' => 'Setting updated successfully']);
    }

    public function getPublicSettings()
    {
        $settings = DB::table('library_settings')->get();
        $formatted = [];
        foreach ($settings as $setting) { $formatted[$setting->key] = $setting->value; }
        return response()->json($formatted);
    }

    public function getUsers(Request $request)
    {
        $this->checkAdmin($request);
        $users = DB::table('users')->select('id', 'name', 'email', 'role', 'created_at')->orderBy('created_at', 'desc')->get();
        return response()->json(['users' => $users]);
    }

    public function updateUserRole(Request $request, $id)
    {
        $this->checkAdmin($request);
        DB::table('users')->where('id', $id)->update(['role' => $request->input('role'), 'updated_at' => now()]);
        return response()->json(['message' => 'User role updated successfully']);
    }

    public function deleteUser(Request $request, $id)
    {
        $this->checkAdmin($request);
        if ($request->user()->id == $id) {
            return response()->json(['message' => 'You cannot delete your own account'], 422);
        }
        $user = DB::table('users')->where('id', $id)->first();
        if ($user) {
            DB::table('otps')->where('email', $user->email)->delete();
            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
            DB::table('book_requests')->where('user_id', $id)->delete();
            DB::table('book_requests')->where('user_id', $id)->delete();
            DB::table('conversations')->where('user_id', $id)->delete();
        }
        DB::table('users')->where('id', $id)->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }

    public function getBooks(Request $request)
    {
        $this->checkAdmin($request);
        $books = DB::table('books')->orderBy('created_at', 'desc')->get();
        return response()->json(['books' => $books]);
    }

    public function getPublicBooks()
    {
        $books = DB::table('books')->orderBy('title', 'asc')->get();
        return response()->json(['books' => $books]);
    }

    public function getNewArrivals()
    {
        $books = DB::table('books')->where('is_new_arrival', true)->orderBy('updated_at', 'desc')->get();
        return response()->json(['books' => $books]);
    }

    public function addBook(Request $request)
    {
        $this->checkAdmin($request);
        $stock = $request->input('stock', 0);
        $id = DB::table('books')->insertGetId([
            'title' => $request->input('title'),
            'author' => $request->input('author'),
            'isbn' => $request->input('isbn'),
            'category' => $request->input('category'),
            'stock' => $stock,
            'location' => $request->input('location'),
            'status' => $stock > 0 ? 'available' : 'unavailable',
            'is_new_arrival' => $request->input('is_new_arrival', false),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $book = DB::table('books')->where('id', $id)->first();
        return response()->json(['message' => 'Book added successfully', 'book' => $book]);
    }

    public function updateBook(Request $request, $id)
    {
        $this->checkAdmin($request);
        $stock = $request->input('stock', 0);
        DB::table('books')->where('id', $id)->update([
            'title' => $request->input('title'),
            'author' => $request->input('author'),
            'isbn' => $request->input('isbn'),
            'category' => $request->input('category'),
            'stock' => $stock,
            'location' => $request->input('location'),
            'status' => $stock > 0 ? 'available' : 'unavailable',
            'is_new_arrival' => $request->input('is_new_arrival', false),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Book updated successfully']);
    }

    public function toggleNewArrival(Request $request, $id)
    {
        $this->checkAdmin($request);
        $book = DB::table('books')->where('id', $id)->first();
        DB::table('books')->where('id', $id)->update([
            'is_new_arrival' => !$book->is_new_arrival,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'New arrival status toggled']);
    }

    public function deleteBook(Request $request, $id)
    {
        $this->checkAdmin($request);
        DB::table('books')->where('id', $id)->delete();
        return response()->json(['message' => 'Book deleted successfully']);
    }

    public function importBooks(Request $request)
    {
        $this->checkAdmin($request);
        if (!$request->hasFile('csv_file')) {
            return response()->json(['message' => 'No file uploaded'], 422);
        }
        $file = $request->file('csv_file');
        $handle = fopen($file->getPathname(), 'r');
        $header = fgetcsv($handle);
        $imported = 0; $errors = 0;
        while (($row = fgetcsv($handle)) !== false) {
            try {
                if (empty($row[0])) continue;
                $stock = isset($row[4]) ? (int)$row[4] : 0;
                DB::table('books')->insert([
                    'title' => $row[0] ?? '', 'author' => $row[1] ?? '',
                    'isbn' => $row[2] ?? null, 'category' => $row[3] ?? null,
                    'stock' => $stock, 'location' => $row[5] ?? null,
                    'status' => $stock > 0 ? 'available' : 'unavailable',
                    'is_new_arrival' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $imported++;
            } catch (\Exception $e) { $errors++; }
        }
        fclose($handle);
        return response()->json(['message' => "Import complete. {$imported} books imported, {$errors} failed.", 'imported' => $imported, 'errors' => $errors]);
    }

    public function getKnowledge(Request $request)
    {
        $this->checkAdmin($request);
        $knowledge = DB::table('knowledge_base')->orderBy('category')->orderBy('created_at', 'desc')->get();
        return response()->json(['knowledge' => $knowledge]);
    }

    public function addKnowledge(Request $request)
    {
        $this->checkAdmin($request);
        $id = DB::table('knowledge_base')->insertGetId([
            'category' => $request->input('category', 'general'),
            'question' => $request->input('question'),
            'answer' => $request->input('answer'),
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = DB::table('knowledge_base')->where('id', $id)->first();
        return response()->json(['message' => 'Knowledge added successfully', 'item' => $item]);
    }

    public function updateKnowledge(Request $request, $id)
    {
        $this->checkAdmin($request);
        DB::table('knowledge_base')->where('id', $id)->update([
            'category' => $request->input('category', 'general'),
            'question' => $request->input('question'),
            'answer' => $request->input('answer'),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Knowledge updated successfully']);
    }

    public function deleteKnowledge(Request $request, $id)
    {
        $this->checkAdmin($request);
        DB::table('knowledge_base')->where('id', $id)->delete();
        return response()->json(['message' => 'Knowledge deleted successfully']);
    }

    public function toggleKnowledge(Request $request, $id)
    {
        $this->checkAdmin($request);
        $item = DB::table('knowledge_base')->where('id', $id)->first();
        DB::table('knowledge_base')->where('id', $id)->update(['active' => !$item->active, 'updated_at' => now()]);
        return response()->json(['message' => 'Knowledge toggled successfully']);
    }

    public function getPersonality(Request $request)
    {
        $this->checkAdmin($request);
        $personality = DB::table('library_settings')->whereIn('key', ['ai_name', 'ai_greeting', 'ai_tone', 'ai_restrictions'])->get();
        $formatted = [];
        foreach ($personality as $p) { $formatted[$p->key] = $p->value; }
        return response()->json($formatted);
    }

    public function updatePersonality(Request $request)
    {
        $this->checkAdmin($request);
        $fields = ['ai_name', 'ai_greeting', 'ai_tone', 'ai_restrictions'];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $value = (string) $request->input($field);
                $exists = DB::table('library_settings')->where('key', $field)->exists();
                if ($exists) {
                    DB::table('library_settings')->where('key', $field)->update(['value' => $value, 'updated_at' => now()]);
                } else {
                    DB::table('library_settings')->insert(['key' => $field, 'value' => $value, 'label' => ucfirst(str_replace('_', ' ', $field)), 'type' => 'text', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        return response()->json(['message' => 'AI personality updated successfully']);
    }

    public function getConversationLogs(Request $request)
    {
        $this->checkAdmin($request);
        $logs = DB::table('conversations')
            ->leftJoin('users', 'conversations.user_id', '=', 'users.id')
            ->select('conversations.id', 'conversations.session_id', 'conversations.sender', 'conversations.message', 'conversations.created_at', 'users.name as user_name', 'users.email as user_email')
            ->orderBy('conversations.created_at', 'desc')
            ->paginate(100);
        return response()->json(['logs' => $logs]);
    }
}
