<?php

namespace Simp\Pindrop\Modules\yt_dlp_downloader\src\services;

use Simp\Pindrop\Database\DatabaseService;

class Yt
{
    public function __construct(protected DatabaseService $database_service)
    {
    }

    public function addLink(string $url, string $local_path, string $name)
    {
        return $this->database_service->table('yt_dlp_links')
        ->insert([
            'link' => $url,
            'local_path' => $local_path,
            'name' => $name
        ]);
    }

    public function getPendingLinks(int $limit = 1)
    {
        return $this->database_service->table('yt_dlp_links')
        ->where('status', '=', 'pending')
        ->limit($limit)
        ->get();
    }

    public function updateLinkStatus(int $id, string $status)
    {
        $this->database_service->table('yt_dlp_links')
        ->where('id', '=', $id)
        ->update([
            'status' => $status
        ]);
    }

    public function getLinkById(int $id)
    {
        return $this->database_service->table('yt_dlp_links')
        ->where('id', '=', $id)
        ->first();
    }

    public function getAllLinks()
    {
        return $this->database_service->table('yt_dlp_links')
        ->get();
    }

    public function deleteLink(int $id)
    {
        return $this->database_service->table('yt_dlp_links')
        ->where('id', '=', $id)
        ->delete();
    }

    public function update(int $id, array $data)
    {
        return $this->database_service->table('yt_dlp_links')
        ->where('id', '=', $id)
        ->update($data);
    }
}
