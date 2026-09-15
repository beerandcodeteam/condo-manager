<?php

namespace App\Services\Notices;

use App\Models\Condominium;
use App\Models\Notice;
use App\Models\User;

/**
 * Notices of a condominium. Only the `is_active` flag decides what the agent sees: no embedding, no validity dates.
 */
class NoticeService
{
    /**
     * @param  array{title: string, body: string, is_active: bool}  $attributes
     */
    public function create(Condominium $condominium, User $author, array $attributes): Notice
    {
        return $condominium->notices()->create([
            ...$this->noticeData($attributes),
            'created_by_user_id' => $author->id,
        ]);
    }

    /**
     * @param  array{title: string, body: string, is_active: bool}  $attributes
     */
    public function update(Notice $notice, array $attributes): Notice
    {
        $notice->update($this->noticeData($attributes));

        return $notice;
    }

    public function setActive(Notice $notice, bool $isActive): Notice
    {
        $notice->update(['is_active' => $isActive]);

        return $notice;
    }

    /**
     * Soft delete: the row stays with `deleted_at`, out of both panel tabs and the API.
     */
    public function delete(Notice $notice): void
    {
        $notice->delete();
    }

    /**
     * @param  array{title: string, body: string, is_active: bool}  $attributes
     * @return array{title: string, body: string, is_active: bool}
     */
    private function noticeData(array $attributes): array
    {
        return [
            'title' => trim($attributes['title']),
            'body' => trim($attributes['body']),
            'is_active' => $attributes['is_active'],
        ];
    }
}
