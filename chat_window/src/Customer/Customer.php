<?php

namespace Simp\Pindrop\Modules\chat_window\src\Customer;

use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Database\DatabaseService;

class Customer
{
    protected string $first_name;
    protected string $last_name;
    protected string $email;
    protected ?string $phone;
    protected int $id;
    protected ?string $created_at = null;

    public function __construct(protected DatabaseService $database)
    {
    }

    /**
     * @throws DatabaseException
     */
    public function createCustomer(string $first_name, string $last_name, string $email, ?string $phone): bool
    {
        $this->first_name = $first_name;
        $this->last_name = $last_name;
        $this->email = $email;
        $this->phone = $phone;

        $query = "SELECT * FROM customer WHERE email = :email";
        $st = $this->database->query($query, $email);
        $result = $st->fetch();

        if ($result) {
           $this->id = $result['id'];
           $this->created_at = $result['created_at'];
           return true;
        }

        $query = "INSERT INTO customer (first_name, last_name, email, phone) VALUES (:first_name, :last_name, :email, :phone)";
        $st = $this->database->query($query, ...$i=[
            'first_name' => $first_name,
            'last_name' => $last_name,
            'email' => $email,
            'phone' => $phone,
        ]);
        if ($st) {
            $this->id = $this->database->lastInsertId();

            return true;
        }
        return false;
    }

    public function getFirstName(): string
    {
        return $this->first_name;
    }

    public function setFirstName(string $first_name): void
    {
        $this->first_name = $first_name;
    }

    public function getLastName(): string
    {
        return $this->last_name;
    }

    public function setLastName(string $last_name): void
    {
        $this->last_name = $last_name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): void
    {
        $this->phone = $phone;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    /**
     * @throws DatabaseException
     */
    public function load(int $id)
    {
        $query = "SELECT * FROM customer WHERE id = :id";
        $st = $this->database->query($query, $id);
        $result = $st->fetch();
        if ($result) {
            $this->id = $result['id'];
            $this->first_name = $result['first_name'];
            $this->last_name = $result['last_name'];
            $this->email = $result['email'];
            $this->phone = $result['phone'];
            $this->created_at = $result['created_at'];
            return true;
        }
        return false;
    }

    public function getCreated()
    {
        return $this->created_at ?? null;
    }

}