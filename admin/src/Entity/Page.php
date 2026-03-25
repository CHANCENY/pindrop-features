<?php

declare(strict_types=1);

namespace Simp\Pindrop\Modules\admin\src\Entity;

use Simp\Pindrop\Content\Storage\StorageEntity;

class Page extends StorageEntity
{
    /**
     * Define the field configuration for this entity
     */
    public function fieldDefinitions(): array
    {
        return [
            'table' => 'content_pages',
            'reference_field' => 'entity_id',
            'data_field' => 'field_data',
            'fields' => [
                'title' => [
                    'type' => 'string',
                    'required' => true,
                    'max_length' => 255,
                    'description' => 'Page title'
                ],
                'content' => [
                    'type' => 'text',
                    'required' => true,
                    'description' => 'Page content'
                ],
                'slug' => [
                    'type' => 'string',
                    'required' => true,
                    'max_length' => 255,
                    'description' => 'URL slug for the page'
                ],
                'meta_title' => [
                    'type' => 'string',
                    'required' => false,
                    'max_length' => 255,
                    'description' => 'SEO meta title'
                ],
                'meta_description' => [
                    'type' => 'text',
                    'required' => false,
                    'description' => 'SEO meta description'
                ],
                'meta_keywords' => [
                    'type' => 'string',
                    'required' => false,
                    'max_length' => 500,
                    'description' => 'SEO meta keywords'
                ],
                'template' => [
                    'type' => 'string',
                    'required' => false,
                    'max_length' => 100,
                    'default' => 'default',
                    'description' => 'Page template to use'
                ],
                'layout' => [
                    'type' => 'string',
                    'required' => false,
                    'max_length' => 100,
                    'default' => 'default',
                    'description' => 'Layout to use for the page'
                ],
                'featured_image' => [
                    'type' => 'string',
                    'required' => false,
                    'max_length' => 500,
                    'description' => 'Featured image path or URL'
                ],
                'excerpt' => [
                    'type' => 'text',
                    'required' => false,
                    'description' => 'Page excerpt or summary'
                ],
                'parent_id' => [
                    'type' => 'integer',
                    'required' => false,
                    'description' => 'Parent page ID for hierarchical pages'
                ],
                'order' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 0,
                    'description' => 'Display order'
                ],
                'is_homepage' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                    'description' => 'Whether this is the homepage'
                ],
                'is_published' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => true,
                    'description' => 'Whether the page is published'
                ],
                'published_at' => [
                    'type' => 'datetime',
                    'required' => false,
                    'description' => 'Publication date and time'
                ],
                'menu_order' => [
                    'type' => 'integer',
                    'required' => false,
                    'default' => 0,
                    'description' => 'Order in navigation menu'
                ],
                'show_in_menu' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => true,
                    'description' => 'Whether to show in navigation menu'
                ],
                'password_protected' => [
                    'type' => 'boolean',
                    'required' => false,
                    'default' => false,
                    'description' => 'Whether the page is password protected'
                ],
                'password' => [
                    'type' => 'string',
                    'required' => false,
                    'max_length' => 255,
                    'description' => 'Page password for protection'
                ],
                'custom_css' => [
                    'type' => 'text',
                    'required' => false,
                    'description' => 'Custom CSS for the page'
                ],
                'custom_js' => [
                    'type' => 'text',
                    'required' => false,
                    'description' => 'Custom JavaScript for the page'
                ],
                'redirect_url' => [
                    'type' => 'string',
                    'required' => false,
                    'max_length' => 500,
                    'description' => 'URL to redirect to (if page is a redirect)'
                ],
                'redirect_type' => [
                    'type' => 'string',
                    'required' => false,
                    'max_length' => 10,
                    'default' => '301',
                    'description' => 'Redirect type (301, 302, etc.)'
                ]
            ]
        ];
    }
}
