<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\MediaStoreRequest;
use App\Http\Resources\Api\AgentMediaResource;
use App\Services\Integration\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * Media a resident sent over WhatsApp. Uploaded by the n8n flow as soon as it arrives, so the agent
 * can attach it to a ticket later by id.
 */
class MediaController extends Controller
{
    /**
     * POST /api/v1/media — store one incoming file and return the id the agent uses to attach it.
     */
    public function store(MediaStoreRequest $request, MediaService $mediaService): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $media = $mediaService->store(
            $file,
            $request->string('phone')->toString(),
            $request->input('caption'),
            $request->input('transcription'),
        );

        return response()->json(new AgentMediaResource($media), 201);
    }
}
