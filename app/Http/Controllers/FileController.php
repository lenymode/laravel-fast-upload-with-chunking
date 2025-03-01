<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class FileController extends Controller
{
    public function uploadChunk(Request $request)
    {
        $file = $request->file('file');
        $chunkNumber = $request->input('chunkNumber');
        $totalChunks = $request->input('totalChunks');
        $identifier = $request->input('identifier');
        $originalName = $request->input('originalName');

        // Validate chunk
        $request->validate([
            'file' => 'required|file',
            'chunkNumber' => 'required|numeric',
            'totalChunks' => 'required|numeric',
            'identifier' => 'required|string',
            'originalName' => 'required|string',
        ]);

        // Create temporary directory
        $tempDir = public_path('uploads/chunks/' . $identifier);

        if (!file_exists($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Save chunk
        $file->move($tempDir, $chunkNumber);

        // Check if all chunks uploaded
        $uploadedChunks = count(glob("$tempDir/*"));
        if ($uploadedChunks == $totalChunks) {
            return $this->mergeChunks($identifier, $originalName);
        }

        return response()->json(['success' => true]);
    }

    private function mergeChunks($identifier, $originalName)
    {
        $tempDir = public_path('uploads/chunks/' . $identifier);
        $finalPath = public_path('uploads/videos/' . $originalName);

        // Create the target directory if it doesn't exist
        if (!file_exists(public_path('uploads/videos'))) {
            mkdir(public_path('uploads/videos'), 0755, true);
        }

        // Open final file
        $finalFile = fopen($finalPath, 'wb');

        if (!$finalFile) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create the final file.',
            ], 500);
        }

        // Merge chunks
        $chunkFiles = glob("$tempDir/*");
        foreach ($chunkFiles as $chunkFile) {
            $chunkHandle = fopen($chunkFile, 'rb');
            if ($chunkHandle) {
                stream_copy_to_stream($chunkHandle, $finalFile);
                fclose($chunkHandle);
                unlink($chunkFile); // Delete the chunk file
            } else {
                fclose($finalFile);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to read a chunk file.',
                ], 500);
            }
        }

        fclose($finalFile);

        // Delete the temporary directory
        if (count(glob("$tempDir/*")) === 0) {
            rmdir($tempDir);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete the temporary directory. It is not empty.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'path' => $finalPath,
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
