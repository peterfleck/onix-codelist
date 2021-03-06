<?php

namespace App\Console\Commands;

use App\Code;
use App\Codelist;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use League\Uri\Modifiers\AppendSegment;
use League\Uri\Schemes\Http as HttpUri;

class UpdateCodelists extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'update:codelists';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Updates codelists';

    /**
     * Guzzle client
     * @var GuzzleHttp\Client
     */
    protected $client;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(\GuzzleHttp\Client $client)
    {
        $this->client = $client;
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Send a request to the JSON formatted codelist
        $response = $this->client->request('GET', $this->formUri());

        // Parse codelists from response
        $onixCodelists = json_decode($response->getBody()->getContents());

        // Disable Algolia auto-indexing temporarily
        Codelist::$autoIndex = false;
        Code::$autoIndex = false;

        foreach ($onixCodelists->CodeList as $onixCodelist) {
            // Create or update codelist
            $codelist = Codelist::firstOrCreate(['number' => $onixCodelist->CodeListNumber]);
            $codelist->description = $onixCodelist->CodeListDescription;
            $codelist->issue_number = $onixCodelist->IssueNumber;
            $codelist->save();

            // In case of many codes, go through array
            if (isset($onixCodelist->Code) && is_array($onixCodelist->Code)) {
                foreach ($onixCodelist->Code as $onixCodelistCode) {
                    $code = Code::updateAndAttach($onixCodelistCode, $codelist);
                }
            }

            // In case of one code, pass the object
            if (isset($onixCodelist->Code) && is_object($onixCodelist->Code)) {
                $code = Code::updateAndAttach($onixCodelist->Code, $codelist);
            }
        }

        // Reindex Algolia and set settings
        if (app()->environment() === 'production') {
            Codelist::clearIndices();
            Codelist::reindex();
            Codelist::setSettings();

            Code::clearIndices();
            Code::reindex();
            Code::setSettings();
        }
    }

    /**
     * Form and URL to the JSON version of the codelist
     * @return League\Uri\Schemes\Http
     */
    public function formUri()
    {
        $uri = HttpUri::createFromString('http://www.editeur.org/files/ONIX for books - code lists/');
        $modifier = new AppendSegment('ONIX_BookProduct_Codelists_Issue_' . config('onix_codelist.issue_number') . '.json');
        $newUri = $modifier->__invoke($uri);
        return $newUri;
    }
}
