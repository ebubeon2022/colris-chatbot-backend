<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookRequestController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'reason' => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        $id = DB::table('book_requests')->insertGetId([
            'user_id' => $user->id,
            'title' => $request->title,
            'author' => $request->author ?? '',
            'reason' => $request->reason ?? '',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Book request submitted successfully.',
            'id' => $id,
        ]);
    }

    public function myRequests(Request $request)
    {
        $user = $request->user();
        $requests = DB::table('book_requests')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json(['requests' => $requests]);
    }

    public function index(Request $request)
    {
        $this->checkAdmin($request);
        $requests = DB::table('book_requests')
            ->join('users', 'book_requests.user_id', '=', 'users.id')
            ->select('book_requests.*', 'users.name as user_name', 'users.email as user_email')
            ->orderBy('book_requests.created_at', 'desc')
            ->get();
        return response()->json(['requests' => $requests]);
    }

    public function update(Request $request, $id)
    {
        $this->checkAdmin($request);
        DB::table('book_requests')->where('id', $id)->update([
            'status' => $request->status,
            'admin_note' => $request->admin_note ?? '',
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Request updated successfully.']);
    }

    private function checkAdmin(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Unauthorized');
        }
    }
}
