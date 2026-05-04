<?php

use Simp\Pindrop\Database\DatabaseService;
use Simp\Pindrop\Entity\User\User;

return [
    'user:get' => 'loadUser',
    'user' => 'help',
    'user:update' => 'userUpdate',
    'user:create' => 'createUser',
    'user:count' => 'countUser',
    'user:all'   => 'loadByLimit'
];

/**
 * Summary of loadUser
 * @param \CLIPrinter $cLIPrinter
 * @param array $values
 * @return void
 */
function loadUser(\CLIPrinter $cLIPrinter, ...$values)
{

    $flag = $values[1][2] ?? null;
    $value = $values[1][3] ?? null;

    if ($flag === null) {
        help($cLIPrinter, ...$values);
        return;
    }

    $supportedFlags = ['-u', '-e', '-s'];

    if (!in_array($flag, $supportedFlags)) {
        help($cLIPrinter, ...$values);
        return;
    }

    if (empty($value)) {
        help($cLIPrinter, ...$values);
        return;
    }

    if (!\getAppContainer()->has('database')) {
        $cLIPrinter->printLine('Connection problem', RED);
        return;
    }

    $database = \getAppContainer()->get('database');

    if ($flag === '-u') {
        $user = User::loadById((int) $value, $database);
        if ($user === null) {
            $cLIPrinter->printLine('No User Found!', YELLOW);
            return;
        }

        $cLIPrinter->printLine(json_encode($user->toArray(), JSON_PRETTY_PRINT), GREEN);
    } elseif ($flag === '-e') {
        $user = User::loadByEmail(trim($value), $database);
        if ($user === null) {
            $cLIPrinter->printLine('No User Found!', RED);
            return;
        }
        $cLIPrinter->printLine(json_encode($user->toArray(), JSON_PRETTY_PRINT), GREEN);
    } elseif ($flag === '-s') {
        $user = User::loadByUsername(trim($value), $database);
        if ($user === null) {
            $cLIPrinter->printLine('No User Found', RED);
            return;
        }
        $cLIPrinter->printLine(json_encode($user->toArray(), JSON_PRETTY_PRINT), GREEN);
    }
}

function help(\CLIPrinter $cLIPrinter, ...$values)
{
    $content = <<<HELP

   commands:
     1. user:get
        This command loads user data and works with these flags
        -u this is followed by user id
        -e this is followed by user email
        -s this is followed by user username
    2. user:update
       This command update user data and works with these flags
        -u this is followed by user id
        -e this is followed by user email
        -s this is followed by user username
        -values this is followed by columns values
       Then to set values for update you need to no users table columns eg
        email=joe@gmail.com first_name=Joe this means update columns email and first_name with given values
    3. user:create
       This command create new user and works with these flags
        -values this is followed by columns values
    4. user:count
       This command shows total users
    5. user:all
       This is command for loading all users provided the limit and page
       -l The limit value
       -p The page value
          
   HELP;

    $cLIPrinter->printLine($content);
}

/**
 * Summary of userUpdate
 * @param \CLIPrinter $cLIPrinter
 * @param array $values
 * @return void
 */
function userUpdate(\CLIPrinter $cLIPrinter, ...$values)
{


    $flag = $values[1][2] ?? null;
    $value = $values[1][3] ?? null;

    if ($flag === null) {
        help($cLIPrinter, ...$values);
        return;
    }

    $supportedFlags = ['-u', '-e', '-s'];

    if (!in_array($flag, $supportedFlags)) {
        help($cLIPrinter, ...$values);
        return;
    }

    if (empty($value)) {
        help($cLIPrinter, ...$values);
        return;
    }

    if (!\getAppContainer()->has('database')) {
        $cLIPrinter->printLine('Connection problem', RED);
        return;
    }

    /**
     * @var DatabaseService $database
     */
    $database = \getAppContainer()->get('database');

    $updateColumns = array_slice($values[1], (array_search('-values', $values[1]) ?? 0) + 1, count($values[1]));

    if (empty($updateColumns)) {
        $cLIPrinter->printLine('No columns/values given these goes after flag -values', RED);
        return;
    }

    if ($updateColumns[0] === 'user:update') {
        $cLIPrinter->printLine('command is incorrect. check if -values exist', RED);
        return;
    }

    $parseColumns = [];
    foreach ($updateColumns as $input) {
        $list = explode('=', $input);
        if (count($list) === 2) {
            $parseColumns[$list[0]] = $list[1];
        }
    }

    $user = null;
    $where = [];
    if ($flag === '-u') {
        $user = User::loadById((int) $value, $database);
        $where['id'] = (int) $value;
    } else if ($flag === '-e') {
        $user = User::loadByEmail(trim($value), $database);
        $where['email'] = trim($value);
    } else if ($flag === '-s') {
        $user = User::loadByUsername(trim($value), $database);
        $where['username'] = trim($value);
    }

    if ($user instanceof User) {
        $placeholders = array_map(function ($item) {
            return "`{$item}`= :{$item}";
        }, array_keys($parseColumns));

        $wherePlaceholder = array_map(function ($item) {
            return "`{$item}` = :{$item}";
        }, array_keys($where));

        $query = "UPDATE `users` SET " . implode(", ", $placeholders) . " WHERE " . implode("AND", $wherePlaceholder);

        $params = $parseColumns + $where;


        $rows = $database->query($query, ...$params)?->rowCount() ?? 0;

        $cLIPrinter->printLine("Update done $rows users affected", GREEN);

    }

}


function createUser(\CLIPrinter $cLIPrinter, ...$values)
{
    $flag = $values[1][2] ?? null;
    $value = $values[1][3] ?? null;

    if ($flag === null) {
        help($cLIPrinter, ...$values);
        return;
    }

    $supportedFlags = ['-values'];

    if (!in_array($flag, $supportedFlags)) {
        help($cLIPrinter, ...$values);
        return;
    }

    if (empty($value)) {
        help($cLIPrinter, ...$values);
        return;
    }

    if (!\getAppContainer()->has('database')) {
        $cLIPrinter->printLine('Connection problem', RED);
        return;
    }

    /**
     * @var DatabaseService $database
     */
    $database = \getAppContainer()->get('database');

    $updateColumns = array_slice($values[1], (array_search('-values', $values[1]) ?? 0) + 1, count($values[1]));

    if (empty($updateColumns)) {
        $cLIPrinter->printLine('No columns/values given these goes after flag -values', RED);
        return;
    }

    if ($updateColumns[0] === 'user:create') {
        $cLIPrinter->printLine('command is incorrect. check if -values exist', RED);
        return;
    }

    $parseColumns = [];
    foreach ($updateColumns as $input) {
        $list = explode('=', $input);
        if (count($list) === 2) {
            $parseColumns[$list[0]] = $list[1];
        }
    }


    try {
        $user = new User($parseColumns, $database, \getAppContainer()->get('logger'));
        if (isset($parseColumns['password'])) {
            $user->setPassword($parseColumns['password']);
        }

        if ($user->save()) {
            $cLIPrinter->printLine('User created uid: ' . $user->getId(), GREEN);
        } else {
            $cLIPrinter->printLine('Failed to create user', RED);
        }



    } catch (Throwable $e) {
        $cLIPrinter->printLine($e->getMessage(), RED);
    }
}


function countUser(\CLIPrinter $cLIPrinter, ...$values)
{

    if (!\getAppContainer()->has('database')) {
        $cLIPrinter->printLine('Connection problem', RED);
        return;
    }

    /**
     * @var DatabaseService $database
     */
    $database = \getAppContainer()->get('database');

    $cLIPrinter->printLine("User count: ".User::count($database), GREEN);
}

function loadByLimit(\CLIPrinter $cLIPrinter, ...$values) {
    if (!\getAppContainer()->has('database')) {
        $cLIPrinter->printLine('Connection problem', RED);
        return;
    }

    /**
     * @var DatabaseService $database
     */
    $database = \getAppContainer()->get('database');

    $limit = $values[1][array_search('-l', $values[1]) + 1] ?? 10;
    $page_number = $values[1][array_search('-p', $values[1]) + 1] ?? 1;
    $page_number = empty($page_number) ?1: $page_number;

    $users = User::loadWithPagination($page_number, $limit,$database);

    foreach ($users['users'] ?? [] as $user) {
        $cLIPrinter->printLine($user, GREEN);
    }
}