<?php

use DI\DependencyException;
use DI\NotFoundException;
use Simp\Pindrop\Database\DatabaseException;
use Simp\Pindrop\Modules\streamer\src\Http\Http;
use Simp\Pindrop\Modules\streamer\src\Plugin\Movie;

$printer = new \CLIPrinter();

return [
    'movie:discover-creation' => 'discoverMovieCreation',
];

/**
 * @throws DatabaseException
 * @throws NotFoundException
 * @throws DependencyException
 */
function discoverMovieCreation(CLIPrinter $printer, ...$values): void
{
    global $printer;

    $page = (int) $printer->ask("Discover from page: [1]", 1,function ($input) use ($printer) {
        return is_numeric($input);
    });

    $page2 = (int) $printer->ask("End on page: [2]", 2,function ($input) use ($printer) {
        return is_numeric($input);
    });



    /**@var Http $http **/
    $http = \getAppContainer()->get('streamer.http');
    $config = $http->getConfig();

    /**@var Movie $movie **/
    $movie = \getAppContainer()->get('streamer.movie');

    for ($i = $page; $i <= $page2; $i++) {
        $http->setParams(['page' => $i]);
        $http->request($config->get('cli.movie.discover.path'));
        $results = Http::parseBodyFields($http->getResponseBody()['results'] ?? [],'cli.movie.discover.results');

        $table = [["TITLE", "RELEASE DATE", "CREATED"]];

        //dd($results[0]);
        foreach ($results as $result) {
            $m = $movie->getMovie(['movie_id' => $result['id']]);
            $validated = [
                'video_file_id' => 48,
                'video_path' => 'public://videos/09-03-2026 14-43-12/tfpdl-ght51172x.mkv',
                'featured'   => 0,
                'release_date' => $m['release_date'],
                'title'        => $m['title'],
                'genres'       => json_encode(array_map(function ($item){ return ['id'=>$item['id'], 'value'=> $item['name']]; }, $m['genres'] ?? [])),
                'duration'     => $m['runtime'],
                'rating'       => $m['vote_average'],
                'popularity'   => $m['vote_count'],
                'description'  => $m['overview'],
                'thumbnail_path'  => $m['poster_path'],
                'imdb_id'         => $m['imdb_id'],
            ];

            $exist = $movie->getMoviesLocally(['imdb_id'=> $m['imdb_id']])['movies'] ?? [];
            if (empty($exist) ) {

                if ($movie->createMovie($validated)) {
                    $table[] = [$m['title'], $m['release_date'], "YES"];
                } else {
                    $table[] = [$m['title'], $m['release_date'], "NO"];
                }
            }
        }

        $printer->printTable($table,['white', 'yellow', 'green']);
    }
}