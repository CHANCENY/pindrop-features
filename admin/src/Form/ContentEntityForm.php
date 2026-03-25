<?php

namespace Simp\Pindrop\Modules\admin\src\Form;

use Simp\Pindrop\Content\Storage\StorageEntity;
use Simp\Pindrop\Form\FormBase;
use Simp\Pindrop\Form\FormStateInterface;
use Simp\Pindrop\Logger\LoggerInterface;

class ContentEntityForm extends FormBase
{
    private string $formId;

    protected StorageEntity $entity;

    protected LoggerInterface $logger;

    public function __construct(array $definition = [])
    {
        $this->formId = 'content_entity_form_'. ($definition['form_id'] ?? 'type');
        $this->entity = $definition['entity'];
        $this->logger = $definition['logger'];
    }

    public function getFormId(): string
    {
        return $this->formId;
    }

    public function buildForm(array $form, FormStateInterface $formState)
    {
        // Get the dynamic fields from the entity
        $fieldDefinitions = $this->entity->fieldDefinitions();
        
        // Basic content fields
        $form['basic_fields'] = [
            '#fieldset' => [
                'legend' => 'Basic Information'
            ],
            'fields' => [
                'title' => [
                    'type' => 'text',
                    'title' => 'Title',
                    'value' => (string) ($this->entity->getTitle() ?? ''),
                    'placeholder' => 'Enter content title',
                    'required' => true,
                    'maxlength' => 255
                ],
                'slug' => [
                    'type' => 'text',
                    'title' => 'URL Slug',
                    'value' => (string) ($this->entity->getSlug() ?? ''),
                    'placeholder' => 'url-friendly-slug',
                    'maxlength' => 255,
                    'help' => 'Leave empty to auto-generate from title'
                ],
                'excerpt' => [
                    'type' => 'textarea',
                    'title' => 'Excerpt',
                    'value' => (string) ($this->entity->getExcerpt() ?? ''),
                    'placeholder' => 'Brief description of the content',
                    'rows' => 3,
                    'help' => 'Short description used in previews and meta tags'
                ],
                'content' => [
                    'type' => 'textarea',
                    'title' => 'Content',
                    'value' => (string) ($this->entity->getContent() ?? ''),
                    'placeholder' => 'Enter your content here...',
                    'rows' => 15,
                    'required' => true,
                    'class' => 'rich-editor'
                ]
            ]
        ];

        // Publication settings
        $form['publication_settings'] = [
            '#fieldset' => [
                'legend' => 'Publication Settings'
            ],
            'fields' => [
                'status' => [
                    'type' => 'select',
                    'title' => 'Status',
                    'value' => (string) ($this->entity->getStatus() ?? 'draft'),
                    'options' => [
                        ['value' => 'draft', 'label' => 'Draft'],
                        ['value' => 'published', 'label' => 'Published'],
                        ['value' => 'archived', 'label' => 'Archived']
                    ]
                ],
                'is_published' => [
                    'type' => 'checkbox',
                    'title' => 'Publish immediately',
                    'value' => $this->entity->isPublished() ? '1' : '0',
                    'checked' => $this->entity->isPublished(),
                    'help' => 'Override status and publish immediately'
                ]
            ]
        ];

        // Dynamic entity fields - map PHP types to HTML form types
        if (isset($fieldDefinitions['fields']) && !empty($fieldDefinitions['fields'])) {
            $form['entity_fields'] = [
                '#fieldset' => [
                    'legend' => ucfirst($this->entity->getNodeType() ?? 'Content') . ' Fields'
                ],
                'fields' => []
            ];

            foreach ($fieldDefinitions['fields'] as $fieldName => $fieldConfig) {
                // Get the PHP type from field definition
                $phpType = $fieldConfig['type'] ?? 'text';
                
                // Map PHP data types to HTML form types
                $htmlFieldType = $this->mapPhpTypeToHtmlType($phpType);
                
                // Debug logging - show the mapping
                $this->logger->debug("Field '$fieldName': PHP type '$phpType' -> HTML type '$htmlFieldType'");
                
                // Get entity value and ensure it's a string
                $entityValue = $this->entity->get($fieldName);
                if (is_array($entityValue)) {
                    // Convert arrays to string (for JSON fields, etc.)
                    $entityValue = json_encode($entityValue);
                    $this->logger->debug("Field '$fieldName': converted array to JSON: $entityValue");
                } elseif (is_bool($entityValue)) {
                    // Convert boolean to string for form display
                    $entityValue = $entityValue ? '1' : '0';
                    $this->logger->debug("Field '$fieldName': converted boolean to string: $entityValue");
                } else {
                    // Convert to string
                    $entityValue = (string) $entityValue;
                }
                
                // Build the field array with HTML type
                $field = [
                    'type' => $htmlFieldType,  // This should be the HTML type, not PHP type
                    'title' => $fieldConfig['label'] ?? $this->formatFieldName($fieldName),
                    'value' => $entityValue ?? ($fieldConfig['default'] ?? ''),
                    'placeholder' => $fieldConfig['placeholder'] ?? '',
                    'help' => $fieldConfig['help'] ?? '',
                    'required' => $fieldConfig['required'] ?? false
                ];
                
                // Add checked property for checkbox fields
                if ($htmlFieldType === 'checkbox') {
                    $field['checked'] = $this->entity->get($fieldName) === true;
                    $field['value'] = $this->entity->get($fieldName) ? '1' : '0';
                    $this->logger->debug("Field '$fieldName': checkbox checked state: " . ($field['checked'] ? 'true' : 'false'));
                }

                // Add type-specific properties
                switch ($htmlFieldType) {
                    case 'textarea':
                        $field['rows'] = $fieldConfig['rows'] ?? 4;
                        // For array fields, add help text about JSON format
                        if ($phpType === 'array') {
                            $field['help'] = ($fieldConfig['help'] ?? '') . ' Enter JSON format (e.g., ["item1", "item2"])';
                            $this->logger->debug("Field '$fieldName': array field - added JSON help text");
                        }
                        break;
                    case 'select':
                        if (isset($fieldConfig['options'])) {
                            $field['options'] = [];
                            foreach ($fieldConfig['options'] as $optionValue => $optionLabel) {
                                $field['options'][] = ['value' => $optionValue, 'label' => $optionLabel];
                            }
                        }
                        break;
                    case 'number':
                        if (isset($fieldConfig['min'])) $field['min'] = $fieldConfig['min'];
                        if (isset($fieldConfig['max'])) $field['max'] = $fieldConfig['max'];
                        break;
                    case 'text':
                        if (isset($fieldConfig['maxlength'])) $field['maxlength'] = $fieldConfig['maxlength'];
                        break;
                }

                // Add the field to the form
                $form['entity_fields']['fields'][$fieldName] = $field;
                $this->logger->debug("Field '$fieldName' added to form with type: " . $field['type']);
            }
        }

        // Meta fields
        $form['meta_fields'] = [
            '#fieldset' => [
                'legend' => 'Meta Information'
            ],
            'fields' => [
                'meta_title' => [
                    'type' => 'text',
                    'title' => 'Meta Title',
                    'value' => (string) ($this->entity->getMetaTitle() ?? ''),
                    'placeholder' => 'SEO title (optional)',
                    'maxlength' => 60,
                    'help' => 'Title for search engines (max 60 characters)'
                ],
                'meta_description' => [
                    'type' => 'textarea',
                    'title' => 'Meta Description',
                    'value' => (string) ($this->entity->getMetaDescription() ?? ''),
                    'placeholder' => 'SEO description (optional)',
                    'rows' => 3,
                    'maxlength' => 160,
                    'help' => 'Description for search engines (max 160 characters)'
                ],
                'meta_keywords' => [
                    'type' => 'text',
                    'title' => 'Meta Keywords',
                    'value' => (string) ($this->entity->getMetaKeywords() ?? ''),
                    'placeholder' => 'keyword1, keyword2, keyword3',
                    'help' => 'Comma-separated keywords for SEO'
                ]
            ]
        ];

        // Advanced settings
        $form['advanced_settings'] = [
            '#fieldset' => [
                'legend' => 'Advanced Settings'
            ],
            'fields' => [
                'featured' => [
                    'type' => 'checkbox',
                    'title' => 'Featured Content',
                    'value' => $this->entity->getFeatured() ? '1' : '0',
                    'checked' => $this->entity->getFeatured(),
                    'help' => 'Mark this content as featured'
                ],
                'sticky' => [
                    'type' => 'checkbox',
                    'title' => 'Sticky Content',
                    'value' => $this->entity->getSticky() ? '1' : '0',
                    'checked' => $this->entity->getSticky(),
                    'help' => 'Keep this content at the top of lists'
                ],
                'allow_comments' => [
                    'type' => 'checkbox',
                    'title' => 'Allow Comments',
                    'value' => $this->entity->getAllowComments() ? '1' : '0',
                    'checked' => $this->entity->getAllowComments(),
                    'help' => 'Allow users to comment on this content'
                ],
                'template' => [
                    'type' => 'text',
                    'title' => 'Template',
                    'value' => (string) ($this->entity->getTemplate() ?? ''),
                    'placeholder' => 'default.twig',
                    'help' => 'Custom template file to use for rendering'
                ],
                'language' => [
                    'type' => 'select',
                    'title' => 'Language',
                    'value' => (string) ($this->entity->getLanguage() ?? 'en'),
                    'options' => [
                        ['value' => 'en', 'label' => 'English'],
                        ['value' => 'es', 'label' => 'Spanish'],
                        ['value' => 'fr', 'label' => 'French'],
                        ['value' => 'de', 'label' => 'German'],
                        ['value' => 'it', 'label' => 'Italian']
                    ]
                ]
            ]
        ];

        // Submit button
        $form['actions'] = [
            '#fieldset' => [
                'legend' => 'Actions'
            ],
            'fields' => [
                'submit' => [
                    'type' => 'submit',
                    'value' => 'Save Content',
                    'class' => 'btn btn-primary'
                ],
                'cancel' => [
                    'type' => 'button',
                    'value' => 'Cancel',
                    'class' => 'btn btn-secondary',
                    'onclick' => 'window.location.href="/admin/content"'
                ]
            ]
        ];

        return $form;
    }

    /**
     * Map PHP data types to HTML form field types
     */
    private function mapPhpTypeToHtmlType(string $phpType): string
    {
        $mappedType = match (strtolower($phpType)) {
            // Text-based fields
            'string' => 'text',
            'text' => 'text',
            'varchar' => 'text',
            'char' => 'text',
            
            // Number fields
            'int', 'integer', 'bigint', 'smallint', 'tinyint' => 'number',
            'float', 'double', 'decimal' => 'number',
            'numeric' => 'number',
            
            // Boolean fields
            'bool', 'boolean' => 'checkbox',
            
            // Date/Time fields
            'date', 'datetime', 'timestamp' => 'datetime-local',
            'time' => 'time',
            
            // Text content (longer text)
            'longtext', 'mediumtext' => 'textarea',
            'blob', 'longblob' => 'textarea',
            
            // Selection fields (if options are provided)
            'enum' => 'select',
            'set' => 'select',
            
            // Array fields - convert to textarea for JSON editing
            'array' => 'textarea',
            
            // File fields
            'file', 'image' => 'file',
            
            // Default to text for unknown types
            default => 'text'
        };
        
        // Debug logging using the application logger
        $this->logger->debug("Mapping PHP type '$phpType' to HTML type '$mappedType'");
        
        return $mappedType;
    }

    /**
     * Format field name into human-readable title
     */
    private function formatFieldName(string $fieldName): string
    {
        // Convert underscores to spaces and capitalize each word
        $formatted = str_replace('_', ' ', $fieldName);
        $formatted = ucwords(strtolower($formatted));
        
        // Handle common abbreviations and special cases
        $formatted = str_replace([
            'Id', 'Url', 'Html', 'Css', 'Js', 'Sql', 'Api', 'Ui', 'Ux',
            'Seo', 'Ssl', 'Tls', 'Http', 'Https', 'Json', 'Xml', 'Csv'
        ], [
            'ID', 'URL', 'HTML', 'CSS', 'JS', 'SQL', 'API', 'UI', 'UX',
            'SEO', 'SSL', 'TLS', 'HTTP', 'HTTPS', 'JSON', 'XML', 'CSV'
        ], $formatted);
        
        return $formatted;
    }

    public function validateForm(array $form, FormStateInterface $formState)
    {
        // Validate title is required
        $title = $formState->getValue('title');
        if (empty(trim($title))) {
            $formState->setErrorByName('title', 'Title is required');
        }

        // Validate content is required
        $content = $formState->getValue('content');
        if (empty(trim($content))) {
            $formState->setErrorByName('content', 'Content is required');
        }

        // Validate slug format if provided
        $slug = $formState->getValue('slug');
        if (!empty(trim($slug))) {
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                $formState->setErrorByName('slug', 'Slug must contain only lowercase letters, numbers, and hyphens');
            }
        }

        // Validate meta title length
        $metaTitle = $formState->getValue('meta_title');
        if (!empty($metaTitle) && strlen($metaTitle) > 60) {
            $formState->setErrorByName('meta_title', 'Meta title must be 60 characters or less');
        }

        // Validate meta description length
        $metaDescription = $formState->getValue('meta_description');
        if (!empty($metaDescription) && strlen($metaDescription) > 160) {
            $formState->setErrorByName('meta_description', 'Meta description must be 160 characters or less');
        }
    }

    public function submitForm(array $form, FormStateInterface $formState)
    {
        // Set basic properties
        $this->entity->setTitle($formState->getValue('title'));
        $this->entity->setContent($formState->getValue('content'));
        $this->entity->setSlug($formState->getValue('slug'));
        $this->entity->setExcerpt($formState->getValue('excerpt'));
        $this->entity->setStatus($formState->getValue('status'));

        // Set publication status
        $isPublished = $formState->getValue('is_published');
        $this->entity->setPublished($isPublished == '1');

        // Set meta fields
        $this->entity->setMetaTitle($formState->getValue('meta_title'));
        $this->entity->setMetaDescription($formState->getValue('meta_description'));
        $this->entity->setMetaKeywords($formState->getValue('meta_keywords'));

        // Set advanced settings
        $this->entity->setFeatured($formState->getValue('featured') == '1');
        $this->entity->setSticky($formState->getValue('sticky') == '1');
        $this->entity->setAllowComments($formState->getValue('allow_comments') == '1');
        $this->entity->setTemplate($formState->getValue('template'));
        $this->entity->setLanguage($formState->getValue('language'));

        // Set dynamic entity fields
        $fieldDefinitions = $this->entity->fieldDefinitions();
        if (isset($fieldDefinitions['fields'])) {
            foreach ($fieldDefinitions['fields'] as $fieldName => $fieldConfig) {
                $value = $formState->getValue($fieldName);
                if ($value !== null) {
                    $this->entity->set($fieldName, $value);
                }
            }
        }

        // Set author if available
        $container = \Simp\Pindrop\getAppContainer();
        $currentUser = $container->get('current_user');
        if ($currentUser && $currentUser->getUser()) {
            $this->entity->setAuthor($currentUser->getUser());
        }

        // Save the entity
        if ($this->entity->save()) {
            $formState->setMessage('Content saved successfully!');
            // Redirect to content list
            $formState->setRedirect('/admin/content');
        } else {
            $formState->setError('form', 'Failed to save content. Please try again.');
        }
    }
}