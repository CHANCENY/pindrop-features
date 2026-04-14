<?php

namespace Simp\Pindrop\Modules\structure\src\Entity;

use Simp\Pindrop\Entity\User\User;

interface NodeInterface
{
    // Getters of node values.
    
    /**
     * Get the title of the node.
     * 
     * Returns the human-readable title that identifies this node.
     * This is typically used in page titles, lists, and breadcrumbs.
     * 
     * @return string The node title
     */
    public function getTitle(): string;
    
    /**
     * Get the author user object of the node.
     * 
     * Returns the complete User entity that created this node.
     * This provides access to author details like name, email, and roles.
     * 
     * @return User The user entity of the node author
     */
    public function getAuthor(): User;
    
    /**
     * Get the URL alias of the node.
     * 
     * Returns the human-readable URL path for this node.
     * This is used for clean URLs and SEO-friendly paths.
     * Example: "articles/my-article-title"
     * 
     * @return string The URL alias for the node
     */
    public function getAlias(): string;

    /**
     * Get the bundle type of the node.
     * 
     * Returns the content type/bundle identifier this node belongs to.
     * This determines the structure and fields available for the node.
     * Example: "articles", "pages", "products"
     * 
     * @return string The bundle identifier
     */
    public function bundle(): string;
    
    /**
     * Get the author's user ID.
     * 
     * Returns the numeric user ID of the node author.
     * This is more efficient than loading the full User object
     * when only the ID is needed for queries or permissions.
     * 
     * @return int The author's user ID
     */
    public function getAuthorId(): int;
    
    /**
     * Get the publication status of the node.
     * 
     * Returns whether the node is currently published or unpublished.
     * Published nodes are visible to the public, unpublished are restricted.
     * 
     * @return bool True if published, false if unpublished
     */
    public function getStatus(): bool;
    
    /**
     * Get the creation timestamp of the node.
     * 
     * Returns the DateTime when this node was originally created.
     * This is used for sorting, timestamps, and content management.
     * 
     * @return \DateTime The creation date and time
     */
    public function getAuthorAt(): \DateTime;

    /**
     * Get the content type of the node.
     * 
     * Returns the specific content type identifier for this node.
     * Similar to bundle() but may include additional type information.
     * 
     * @return string The content type identifier
     */
    public function getType(): string;
    
    /**
     * Get the last modification timestamp of the node.
     * 
     * Returns the DateTime when this node was last modified.
     * This updates whenever the node content or metadata changes.
     * 
     * @return \DateTime The last modification date and time
     */
    public function getChangedAt(): \DateTime;
    
    /**
     * Check if the node is marked as deleted.
     * 
     * Returns whether the node has been soft-deleted.
     * Soft-deleted nodes remain in database but are hidden from normal queries.
     * 
     * @return bool True if deleted, false if active
     */
    public function isDeleted(): bool;
    
    /**
     * Check if the node is published.
     * 
     * Returns whether the node is currently published and visible to users.
     * This is an alias for getStatus() but more semantically clear.
     * 
     * @return bool True if published, false if unpublished
     */
    public function isPublished(): bool;

    /**
     * Get the unique identifier of the node.
     * 
     * Returns the primary key/ID of this node in the database.
     * This is used for database operations and URL generation.
     * 
     * @return int The unique node ID
     */
    public function id(): int;
    
    /**
     * Get the complete entity definition.
     * 
     * Returns the full entity structure including field definitions,
     * metadata, and configuration for this node type.
     * 
     * @return array The complete entity definition structure
     */
    public function entityDefinition(): array;

    /**
     * Get a field value by field name.
     * 
     * Retrieves the value of a specific field on this node.
     * Supports custom fields defined in the entity definition.
     * 
     * @param string $name The field machine name
     * @return mixed The field value
     */
    public function get(string $name);

    /**
     * Get all field values of the node.
     * 
     * Returns an associative array of all field values for this node.
     * Includes both standard fields and custom fields.
     * 
     * @return array All field values indexed by field name
     */
    public function getValues(): array;

    // Setter of node.
    
    /**
     * Set the title of the node.
     * 
     * Updates the human-readable title that identifies this node.
     * This will typically update the page title and navigation labels.
     * 
     * @param string $title The new title for the node
     * @return static Returns the current instance for method chaining
     */
    public function setTitle(string $title): static;
    
    /**
     * Set the author of the node by user ID.
     * 
     * Updates the user ID of the node author.
     * This changes ownership and attribution of the content.
     * 
     * @param int $uid The user ID of the new author
     * @return static Returns the current instance for method chaining
     */
    public function setAuthor(int $uid): static;
    
    /**
     * Set the URL alias of the node.
     * 
     * Updates the human-readable URL path for this node.
     * This affects how the node is accessed via clean URLs.
     * Example: "articles/my-article-title"
     * 
     * @param string $alias The new URL alias for the node
     * @return static Returns the current instance for method chaining
     */
    public function setAlias(string $alias): static;
    
    /**
     * Set the content type of the node.
     * 
     * Updates the content type identifier for this node.
     * This determines which fields and structure are available.
     * 
     * @param string $type The new content type identifier
     * @return static Returns the current instance for method chaining
     */
    public function setType(string $type): static;
    
    /**
     * Set the last modification timestamp.
     * 
     * Updates when this node was last modified.
     * This is typically set automatically when content changes.
     * 
     * @param \DateTime $date The new modification timestamp
     * @return static Returns the current instance for method chaining
     */
    public function setChangedAt(\DateTime $date): static;
    
    /**
     * Set the publication status of the node.
     * 
     * Updates whether the node is published (1) or unpublished (0).
     * Published nodes are visible to public, unpublished are restricted.
     * 
     * @param int $status 1 for published, 0 for unpublished
     * @return static Returns the current instance for method chaining
     */
    public function setStatus(int $status): static;
    
    /**
     * Set the creation timestamp of the node.
     * 
     * Updates when this node was originally created.
     * This is typically set once during node creation.
     * 
     * @param \DateTime $date The new creation timestamp
     * @return static Returns the current instance for method chaining
     */
    public function setAuthorAt(\DateTime $date): static;

    /**
     * Set the author's user ID.
     * 
     * Updates the numeric user ID of the node author.
     * This is an alternative to setAuthor() that accepts only the ID.
     * 
     * @param int $uid The author's user ID
     * @return static Returns the current instance for method chaining
     */
    public function setAuthorId(int $uid): static;

    /**
     * Set a field value by field name.
     * 
     * Updates the value of a specific field on this node.
     * Supports custom fields defined in the entity definition.
     * 
     * @param string $name The field machine name
     * @param mixed $value The new field value
     * @return static Returns the current instance for method chaining
     */
    public function set(string $name, $value): static;

    // Action methods.
    
    /**
     * Save the node to the database.
     * 
     * Persists the current node state to the database.
     * Creates new record if node doesn't exist, updates existing record.
     * Returns the node instance on success or false on failure.
     * 
     * @return static|bool The node instance on success, false on failure
     */
    public function save(): static|bool;
    
    /**
     * Delete the node from the database.
     * 
     * Performs a soft delete operation on the node.
     * The node remains in database but is marked as deleted.
     * Returns the node instance for potential undo operations.
     * 
     * @return static The node instance
     */
    public function delete(): static;

    /**
     * Find a node by its unique ID.
     * 
     * Retrieves a single node from the database using its primary key.
     * Returns null if no node with the given ID exists.
     * 
     * @param int $nid The node ID to search for
     * @return static|null The node instance or null if not found
     */
    public function find(int $nid): ?static;
    
    /**
     * Find a node by its URL alias.
     * 
     * Retrieves a single node using its human-readable URL path.
     * Returns null if no node with the given alias exists.
     * 
     * @param string $alias The URL alias to search for
     * @return static|null The node instance or null if not found
     */
    public function findByAlias(string $alias): ?static;
    
    /**
     * Find a node by its content type.
     * 
     * Retrieves the first node matching the specified content type.
     * This is typically used with additional filters for more specific queries.
     * Returns null if no node of the given type exists.
     * 
     * @param string $type The content type to search for
     * @return array<int>|null The node instance or null if not found
     */
    public function findByType(string $type): ?array;
    
    /**
     * Find a node by its author's user ID.
     * 
     * Retrieves the first node created by the specified user.
     * This is typically used with additional filters for more specific queries.
     * Returns null if no node by the given author exists.
     * 
     * @param int $uid The author's user ID to search for
     * @return array<int>|null The node instance or null if not found
     */
    public function findByAuthorId(int $uid): ?array;
    
    /**
     * Find a node by its publication status.
     * 
     * Retrieves the first node matching the specified status.
     * Status values: 1 for published, 0 for unpublished.
     * Returns null if no node with the given status exists.
     * 
     * @param int $status The publication status to search for
     * @return array<int>|null The node instance or null if not found
     */
    public function findByStatus(int $status): ?array;
    
    /**
     * Find multiple nodes by their authors.
     * 
     * Retrieves all nodes created by any of the specified user IDs.
     * Returns an array of node instances, possibly empty.
     * 
     * @param array $uids Array of author user IDs to search for
     * @return array Array of node instances
     */
    public function findByAuthors(array $uids): array;
    
    /**
     * Find multiple nodes by their content types.
     * 
     * Retrieves all nodes matching any of the specified content types.
     * Returns an array of node instances, possibly empty.
     * 
     * @param array $types Array of content type identifiers
     * @return array Array of node instances
     */
    public function findByTypes(array $types): array;
    
    /**
     * Find multiple nodes by their IDs.
     * 
     * Retrieves all nodes matching any of the specified node IDs.
     * Returns an array of node instances, possibly empty.
     * 
     * @param array $ids Array of node IDs to search for
     * @return array Array of node instances
     */
    public function findByIds(array $ids): array;

    // Helper static methods
    
    /**
     * Create a new node instance from data array.
     * 
     * Factory method to create a new node with the provided data.
     * The data array should contain field names as keys and values as data.
     * Returns a new node instance that hasn't been saved yet.
     * 
     * @param array $data Associative array of node data
     * @return static A new node instance
     */
    public static function create(array $data): static;

    /**
     * Load a node by its unique ID.
     *
     * Static method to retrieve a single node from the database.
     * This is a convenience wrapper around the instance find() method.
     * Returns null if no node with the given ID exists.
     *
     * @param int $nid The node ID to load (array for compatibility)
     * @return static|null The node instance or null if not found
     */
    public static function load(int $nid): ?static;
    
    /**
     * Load a node by its URL alias.
     * 
     * Static method to retrieve a node using its URL path.
     * This is a convenience wrapper around the instance findByAlias() method.
     * Returns null if no node with the given alias exists.
     * 
     * @param string $alias The URL alias to load
     * @return static|null The node instance or null if not found
     */
    public static function loadByAlias(string $alias): ?static;
    
    /**
     * Load a node by its content type.
     * 
     * Static method to retrieve the first node of a specific type.
     * This is a convenience wrapper around the instance findByType() method.
     * Returns null if no node of the given type exists.
     * 
     * @param string $type The content type to load
     * @return array<int>|null The node instance or null if not found
     */
    public static function loadByType(string $type): ?array;
    
    /**
     * Load a node by its author's user ID.
     * 
     * Static method to retrieve the first node by a specific author.
     * This is a convenience wrapper around the instance findByAuthorId() method.
     * Returns null if no node by the given author exists.
     * 
     * @param int $uid The author's user ID to load by
     * @return static|null The node instance or null if not found
     */
    public static function loadByAuthorId(int $uid): ?array;
    
    /**
     * Load a node by its publication status.
     * 
     * Static method to retrieve the first node with a specific status.
     * This is a convenience wrapper around the instance findByStatus() method.
     * Returns null if no node with the given status exists.
     * 
     * @param int $status The publication status to load by
     * @return array|null The node instance or null if not found
     */
    public static function loadByStatus(int $status): ?array;
    
    /**
     * Load multiple nodes by their authors.
     * 
     * Static method to retrieve all nodes by specified authors.
     * This is a convenience wrapper around the instance findByAuthors() method.
     * Returns an array of node instances, possibly empty.
     * 
     * @param array $uids Array of author user IDs to load by
     * @return array Array of node instances
     */
    public static function loadByAuthors(array $uids): array;
    
    /**
     * Load multiple nodes by their IDs.
     * 
     * Static method to retrieve multiple nodes efficiently.
     * This is more efficient than loading nodes individually.
     * Returns an array of node instances, possibly empty.
     * 
     * @param array $nids Array of node IDs to load
     * @return array Array of node instances
     */
    public static function loadMultiple(array $nids): array;

    /**
     * Duplicate an existing node.
     * 
     * Creates a copy of an existing node with a new ID.
     * Accepts either a node instance or node ID as parameter.
     * The duplicate will have the same content but can be modified independently.
     * 
     * @param NodeInterface|int $node The node to duplicate (instance or ID)
     * @return static The new duplicated node instance
     */
    public static function duplicate(NodeInterface|int $node): static;

    /**
     * Get the storage handler for this node type.
     * 
     * Returns the storage service responsible for database operations.
     * This provides access to low-level storage functionality.
     * 
     * @return StorageEntityManager The storage handler instance
     */
    public function storage();

    /**
     * @param array $data
     * @return static
     */
    public function add(array $data): static;

    /**
     * @return array
     */
    public function toArray(): array;

    public static function loadByFields(array $field_names): array;

    public function setDeleted(bool $deleted): static;

    public function getUuid(): string;

    public function setId(int $id): static;

}