<?php

namespace Simp\Pindrop\Modules\chat_window\src\Customer;

use Simp\Pindrop\Database\DatabaseService;

class Customer
{
    protected string $first_name = '';
    protected string $last_name  = '';
    protected string $email      = '';
    protected ?string $phone     = null;
    protected int $id            = 0;
    protected ?string $created_at = null;

    public function __construct(protected DatabaseService $database)
    {
    }

    public function createCustomer(string $first_name, string $last_name, string $email, ?string $phone): bool
    {
        $this->first_name = $first_name;
        $this->last_name  = $last_name;
        $this->email      = $email;
        $this->phone      = $phone;

        // Return existing customer if email already registered
        $existing = $this->database->table('customer')
            ->where('email', '=', $email)
            ->first();

        if ($existing) {
            $this->id         = (int) $existing['id'];
            $this->created_at = $existing['created_at'];
            return true;
        }

        $id = $this->database->table('customer')->insert([
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'email'      => $email,
            'phone'      => $phone,
        ]);

        if ($id) {
            $this->id = $id;
            return true;
        }
        return false;
    }

    public function load(int $id): bool
    {
        $row = $this->database->table('customer')
            ->where('id', '=', $id)
            ->first();

        if ($row) {
            $this->id         = (int) $row['id'];
            $this->first_name = $row['first_name'];
            $this->last_name  = $row['last_name'];
            $this->email      = $row['email'];
            $this->phone      = $row['phone'];
            $this->created_at = $row['created_at'];
            return true;
        }
        return false;
    }

    public function getFirstName(): string  { return $this->first_name; }
    public function getLastName(): string   { return $this->last_name; }
    public function getEmail(): string      { return $this->email; }
    public function getPhone(): ?string     { return $this->phone; }
    public function getId(): int            { return $this->id; }
    public function getCreated(): ?string   { return $this->created_at; }

    public function setFirstName(string $v): void  { $this->first_name = $v; }
    public function setLastName(string $v): void   { $this->last_name  = $v; }
    public function setEmail(string $v): void      { $this->email      = $v; }
    public function setPhone(?string $v): void     { $this->phone      = $v; }
    public function setId(int $v): void            { $this->id         = $v; }
}
