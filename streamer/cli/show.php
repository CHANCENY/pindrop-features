<?php

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Modules\streamer\src\Http\Http;
use Simp\Pindrop\Modules\streamer\src\Plugin\Show;

$printer = new \CLIPrinter();

return [
    'show:discover-creation' => 'discoverShowCreation',
];


/**
 * @throws DatabaseException
 * @throws NotFoundException
 * @throws DependencyException
 */
function discoverShowCreation(CLIPrinter $printer, ...$values): void
{
    $page = (int) $printer->ask("Discover from page: [1]", 1,function ($input) use ($printer) {
        return is_numeric($input);
    });

    $page2 = (int) $printer->ask("End on page: [2]", 2,function ($input) use ($printer) {
        return is_numeric($input);
    });



    /**@var Http $http **/
    $http = \getAppContainer()->get('streamer.http');
    $config = $http->getConfig();

    /**@var Show $showS **/
    $showS = \getAppContainer()->get('streamer.show');

    for ($i = $page; $i <= $page2; $i++) {
        $http->setParams(['page' => $i]);
        $http->request($config->get('cli.show.discover.path'));
        $results = Http::parseBodyFields($http->getResponseBody()['results'] ?? [],'cli.show.discover.results');
        $table = [["TITLE", "RELEASE DATE", "CREATED"]];

        //dd($results[0]);
        foreach ($results as $result) {
            $show = $showS->getShow(['series_id' => $result['id']]);

            $showData = [
                'title'  => $show['name'],
                'genres' => json_encode(array_map(function ($genre) { return ['id'=>$genre['id'], 'value' => $genre['name'] ]; },$show['genres'])),
                'start_year' => $show['first_air_date'],
                'end_year'   =>  !empty($show['last_air_date']) ? $show['last_air_date'] : null,
                'rating'     =>  $show['vote_average'],
                'status'     =>  $show['status'],
                'description'=>  $show['overview'],
                'language'   =>  $show['origin_country'][0],
                'country'    =>  $show['original_language'],
                'poster'     =>  $show['poster_path'],
                'thumbnail_path'=>  $show['poster_path'],
                'season_count'  =>  $show['number_of_seasons'],
                'imdb_id'       =>  $show['id'],
            ];

            $exist = $showS->getShowsLocally(['imdb_id'=> $show['id']])['movies'] ?? [];
            if (empty($exist) ) {

                if ($showS->createShow($showData)) {
                    $table[] = [$show['name'], $show['last_air_date'], "YES"];
                } else {
                    $table[] = [$show['name'], $show['last_air_date'], "NO"];
                }
            }
        }

        $printer->printTable($table,['white', 'yellow', 'green']);
    }
}