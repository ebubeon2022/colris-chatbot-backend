<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class BookController extends Controller
{
    public function search(Request $request)
    {
        $query = $request->input('query');

        if (!$query) {
            return response()->json(['error' => 'Query is required'], 400);
        }

        try {
            $books = [];

            // 1. Search Open Library
            $olResponse = Http::get("https://openlibrary.org/search.json?q=" . urlencode($query) . "&limit=6&fields=title,author_name,first_publish_year,key,isbn,cover_i,subject,publisher,ebook_access");

            if ($olResponse->successful()) {
                $olData = $olResponse->json();
                foreach ($olData['docs'] ?? [] as $book) {
                    $coverId = $book['cover_i'] ?? null;
                    $key = $book['key'] ?? '';
                    $ebookAccess = $book['ebook_access'] ?? 'no_ebook';

                    $accessInfo = match($ebookAccess) {
                        'public'     => ['label' => '🌐 Free to Read Online', 'color' => 'green', 'downloadable' => false],
                        'borrowable' => ['label' => '📥 Borrowable Online',   'color' => 'blue',  'downloadable' => false],
                        default      => ['label' => '📚 Print Only',          'color' => 'gray',  'downloadable' => false],
                    };

                    $books[] = [
                        'source'       => 'Open Library',
                        'title'        => $book['title'] ?? 'Unknown Title',
                        'author'       => isset($book['author_name']) ? implode(', ', array_slice($book['author_name'], 0, 2)) : 'Unknown Author',
                        'year'         => $book['first_publish_year'] ?? 'N/A',
                        'cover'        => $coverId ? "https://covers.openlibrary.org/b/id/{$coverId}-M.jpg" : null,
                        'link'         => $key ? "https://openlibrary.org{$key}" : '',
                        'download_link'=> null,
                        'isbn'         => $book['isbn'][0] ?? null,
                        'publisher'    => isset($book['publisher']) ? $book['publisher'][0] : 'N/A',
                        'subjects'     => isset($book['subject']) ? array_slice($book['subject'], 0, 3) : [],
                        'access_label' => $accessInfo['label'],
                        'access_color' => $accessInfo['color'],
                        'downloadable' => $accessInfo['downloadable'],
                    ];
                }
            }

            // 2. Search Google Books
            $gbResponse = Http::get("https://www.googleapis.com/books/v1/volumes?q=" . urlencode($query) . "&maxResults=6&printType=books");

            if ($gbResponse->successful()) {
                $gbData = $gbResponse->json();
                foreach ($gbData['items'] ?? [] as $item) {
                    $info = $item['volumeInfo'] ?? [];
                    $access = $item['accessInfo'] ?? [];
                    $saleInfo = $item['saleInfo'] ?? [];

                    $isFreePdf = ($access['pdf']['isAvailable'] ?? false) && ($access['viewability'] === 'ALL_PAGES');
                    $previewLink = $info['previewLink'] ?? null;
                    $downloadLink = $isFreePdf ? ($access['pdf']['downloadLink'] ?? null) : null;

                    if ($isFreePdf) {
                        $accessLabel = '⬇️ Free PDF Download';
                        $accessColor = 'green';
                    } elseif ($previewLink) {
                        $accessLabel = '👁️ Preview Available';
                        $accessColor = 'blue';
                    } else {
                        $accessLabel = '📚 No Preview';
                        $accessColor = 'gray';
                    }

                    $books[] = [
                        'source'        => 'Google Books',
                        'title'         => $info['title'] ?? 'Unknown Title',
                        'author'        => isset($info['authors']) ? implode(', ', array_slice($info['authors'], 0, 2)) : 'Unknown Author',
                        'year'          => isset($info['publishedDate']) ? substr($info['publishedDate'], 0, 4) : 'N/A',
                        'cover'         => $info['imageLinks']['thumbnail'] ?? null,
                        'link'          => $previewLink ?? '',
                        'download_link' => $downloadLink,
                        'isbn'          => null,
                        'publisher'     => $info['publisher'] ?? 'N/A',
                        'subjects'      => array_slice($info['categories'] ?? [], 0, 3),
                        'access_label'  => $accessLabel,
                        'access_color'  => $accessColor,
                        'downloadable'  => $isFreePdf,
                    ];
                }
            }

            return response()->json([
                'books' => $books,
                'total' => count($books)
            ]);

        } catch (\Exception $e) {
            \Log::error('Book search error: ' . $e->getMessage());
            return response()->json(['error' => 'Something went wrong'], 500);
        }
    }
}
