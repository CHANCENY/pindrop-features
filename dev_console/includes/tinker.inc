<?php

use Simp\Pindrop\Entity\User\User;

/**
 * Get Container services
 * @param string $service_name
 * @return mixed|T|null
 */
function service(string $service_name) {
    if (\getAppContainer()->has($service_name)) return \getAppContainer()->get($service_name);
    return null;
}

/**
 * Get User by uid.
 * @param int $uid
 * @return User|null
 */
function get_user(int $uid): User|null
{
    return service('user_repository')->findById($uid);
}

/**
 * Get User by email.
 * @param string $email
 * @return User|null
 */
function get_user_by_email(string $email): User|null
{
    return service('user_repository')->findByEmail($email);
}

/**
 * Get user by name.
 * @param string $name
 * @return User|null
 */
function get_user_by_name(string $name): User|null
{
    return service('user_repository')->findByUsername($name);
}

/**
 * Create User Entity object without calling save
 * @param array $values
 * @return User|null
 */
function create_user(...$values): User|null
{
    return service('user_repository')->create($values);
}