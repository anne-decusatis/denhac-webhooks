<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper;

class LinkQuickbooks extends Command
{
    protected $signature = 'denhac:link-quickbooks';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate link to auth denhac QuickBooks instance';
    private OAuth2LoginHelper $OAuth2LoginHelper;

    public function __construct(OAuth2LoginHelper $OAuth2LoginHelper)
    {
        parent::__construct();
        $this->OAuth2LoginHelper = $OAuth2LoginHelper;
    }

    public function handle()
    {
        if (!is_null(setting('quickbooks.accessToken'))) {
            $shouldContinue = $this->confirm("We have an existing access token, are you sure you want to generate a new one?");
            if (!$shouldContinue) {
                return 0;
            }
        }

        $authUrl = $this->OAuth2LoginHelper->getAuthorizationCodeURL();

        $this->info($authUrl);

        return 0;
    }
}
