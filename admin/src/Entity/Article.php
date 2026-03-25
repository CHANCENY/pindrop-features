<?php

namespace Simp\Pindrop\Modules\admin\src\Entity;

use Simp\Pindrop\Content\Storage\StorageEntity;

class Article extends StorageEntity
{

    public function fieldDefinitions(): array
    {
        return [
            'table' => 'content_articles',
            'reference_field' => 'entity_id',
            'data_field' => 'field_data',
            'fields' => [
                'category' => ['type' => 'string', 'required' => false, 'default' => 'general'],
                'tags' => ['type' => 'array', 'required' => false, 'default' => []],
                'featured_image' => ['type' => 'string', 'required' => false],
                'reading_time' => ['type' => 'integer', 'required' => false, 'default' => 0],
                'difficulty_level' => ['type' => 'string', 'required' => false, 'default' => 'beginner'],
                'author_bio' => ['type' => 'string', 'required' => false],
                'source_url' => ['type' => 'string', 'required' => false],
                'video_url' => ['type' => 'string', 'required' => false],
                'download_url' => ['type' => 'string', 'required' => false],
                'is_featured' => ['type' => 'boolean', 'required' => false, 'default' => false],
                'views_count' => ['type' => 'integer', 'required' => false, 'default' => 0],
                'likes_count' => ['type' => 'integer', 'required' => false, 'default' => 0],
                'comments_count' => ['type' => 'integer', 'required' => false, 'default' => 0],
                'shares_count' => ['type' => 'integer', 'required' => false, 'default' => 0],
                'seo_score' => ['type' => 'integer', 'required' => false, 'default' => 0],
                'last_modified_by' => ['type' => 'integer', 'required' => false],
                'related_articles' => ['type' => 'array', 'required' => false, 'default' => []]
            ]
        ];
    }
}