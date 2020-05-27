<?php

/**
 * Copyright 2018 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace App\Http\Controllers;

use Google\AdsApi\AdWords\AdWordsServices;
use Google\AdsApi\AdWords\AdWordsSessionBuilder;
use Google\AdsApi\AdWords\AdWordsSession;
use Google\AdsApi\AdWords\AdGroupCriterionService;
use Google\AdsApi\AdWords\Query\v201809\ReportQueryBuilder;
use Google\AdsApi\AdWords\Query\v201809\ServiceQueryBuilder;
use Google\AdsApi\AdWords\Reporting\v201809\DownloadFormat;
use Google\AdsApi\AdWords\Reporting\v201809\ReportDownloader;
use Google\AdsApi\AdWords\ReportSettingsBuilder;
use Google\AdsApi\AdWords\v201809\cm\CampaignService;
use Google\AdsApi\AdWords\v201809\cm\DataService;
use Google\Auth\FetchAuthTokenInterface;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Google\AdsApi\Common\OAuth2TokenBuilder;

class AdWordsApiController extends Controller
{
    const MARGIN = 25; // %

    private static $REPORT_TYPE_TO_DEFAULT_SELECTED_FIELDS = [
        'CAMPAIGN_PERFORMANCE_REPORT' => [
            'CampaignId',
            'CampaignName',
            'CampaignStatus',
            'AccountDescriptiveName',
            'Impressions',
            'Clicks',
            'Ctr',
            'Cost',
            'Conversions',
            'ConversionValue'
        ],
        'ADGROUP_PERFORMANCE_REPORT' => [
            'AdGroupId',
            'AdGroupName',
            'AdGroupType',
            'AdGroupStatus',
            'CampaignId',
            'Impressions',
            'Clicks',
            'Ctr',
            'Cost',
            'Conversions',
            'CpcBid',
            'ConversionValue'
        ],
        'PRODUCT_PARTITION_REPORT' => [
            'AdGroupId',            
            'Id',
            'ProductGroup',
            'CpcBid',
            'Impressions',
            'Clicks',
            'Cost',
            'Conversions',
            'ConversionValue',
            'ParentCriterionId'
        ],
        'AD_PERFORMANCE_REPORT' => ['AdGroupId', 'AdGroupName', 'Id', 'AdType'],
        'ACCOUNT_PERFORMANCE_REPORT' => [
            'AccountDescriptiveName',
            'ExternalCustomerId'
        ],
    ];
    // 'PRODUCT_PARTITION_REPORT' => [
    //     'AdGroupId',
    //     'AdGroupName',
    //     'Id',
    //     'ProductGroup',
    //     'ParentCriterionId',
    //     'Impressions',
    //     'Ctr',
    //     'CpcBid',
    //     'CostPerConversion',
    //     'Cost'
    // ],


    /**
     * Controls a POST and GET request that is submitted from the "Bid Landscape" form.
     *
     * @param Request $request
     * @param FetchAuthTokenInterface $oAuth2Credential
     * @param AdWordsServices $adWordsServices
     * @param AdWordsSessionBuilder $adWordsSessionBuilder
     * @return View
     */
    public function getBidLandscapes (
        Request $request,
        FetchAuthTokenInterface $oAuth2Credential,
        AdWordsServices $adWordsServices,
        AdWordsSessionBuilder $adWordsSessionBuilder
    ) {

        if ($request->method() === 'POST') {
            // Always select at least the "Id" field.
            $selectedFields = array_values(
                ['id' => 'Id'] + $request->except(
                    ['_token', 'clientCustomerId', 'entriesPerPage']
                )
            );
            $clientCustomerId = $request->input('clientCustomerId');
            $entriesPerPage   = $request->input('entriesPerPage');

            // Construct an API session configured from a properties file and
            // the OAuth2 credentials above.
            $session =
                $adWordsSessionBuilder->fromFile(config('app.adsapi_php_path'))
                    ->withOAuth2Credential($oAuth2Credential)
                    ->withClientCustomerId($clientCustomerId)
                    ->build();

            $request->session()->put('selectedFields', $selectedFields);
            $request->session()->put('entriesPerPage', $entriesPerPage);
            $request->session()->put('session', $session);
        } else {
            $selectedFields = $request->session()->get('selectedFields');
            $entriesPerPage = $request->session()->get('entriesPerPage');
            $session = $request->session()->get('session');
        }

        $pageNo = $request->input('page') ?: 1;
        $collection = self::fetchBidLandscapes(
            $request,
            $adWordsServices->get($session, DataService::class),
            $selectedFields,
            $entriesPerPage,
            $pageNo,
            $oAuth2Credential,
            $adWordsServices
        );

        // Create a length aware paginator to supply campaigns for the view,
        // based on the specified number of entries per page.
        $campaigns = new LengthAwarePaginator(
            $collection,
            $request->session()->get('totalNumEntries'),
            $entriesPerPage,
            $pageNo,
            ['path' => url('get-campaigns')]
        );

        // echo "aaa";
        // foreach ($collection as $c) {
            // var_dump($c);
        // }
        exit;

        return view('landscapes', $campaigns);
    }

    /**
     * Controls a POST and GET request that is submitted from the "Get All
     * Campaigns" form.
     *
     * @param Request $request
     * @param FetchAuthTokenInterface $oAuth2Credential
     * @param AdWordsServices $adWordsServices
     * @param AdWordsSessionBuilder $adWordsSessionBuilder
     * @return View
     */
    public function getCampaignsAction(
        Request $request,
        FetchAuthTokenInterface $oAuth2Credential,
        AdWordsServices $adWordsServices,
        AdWordsSessionBuilder $adWordsSessionBuilder
    ) {
        if ($request->method() === 'POST') {
            // Always select at least the "Id" field.
            $selectedFields = array_values(
                ['id' => 'Id'] + $request->except(
                    ['_token', 'clientCustomerId', 'entriesPerPage']
                )
            );
            $clientCustomerId = $request->input('clientCustomerId');
            $entriesPerPage = $request->input('entriesPerPage');

            // Construct an API session configured from a properties file and
            // the OAuth2 credentials above.
            $session =
                $adWordsSessionBuilder->fromFile(config('app.adsapi_php_path'))
                    ->withOAuth2Credential($oAuth2Credential)
                    ->withClientCustomerId($clientCustomerId)
                    ->build();

            $request->session()->put('selectedFields', $selectedFields);
            $request->session()->put('entriesPerPage', $entriesPerPage);
            $request->session()->put('session', $session);
        } else {
            $selectedFields = $request->session()->get('selectedFields');
            $entriesPerPage = $request->session()->get('entriesPerPage');
            $session = $request->session()->get('session');
        }

        $pageNo = $request->input('page') ?: 1;
        $collection = self::fetchCampaigns(
            $request,
            $adWordsServices->get($session, CampaignService::class),
            $selectedFields,
            $entriesPerPage,
            $pageNo
        );

        // Create a length aware paginator to supply campaigns for the view,
        // based on the specified number of entries per page.
        $campaigns = new LengthAwarePaginator(
            $collection,
            $request->session()->get('totalNumEntries'),
            $entriesPerPage,
            $pageNo,
            ['path' => url('get-campaigns')]
        );

        return view('campaigns', compact('campaigns', 'selectedFields'));
    }

    /**
     * Fetch campaigns using the provided campaign service, selected fields, the
     * number of entries per page and the specified page number.
     *
     * @param Request $request
     * @param CampaignService $campaignService
     * @param string[] $selectedFields
     * @param int $entriesPerPage
     * @param int $pageNo
     * @return Collection
     */
    private function fetchCampaigns(
        Request $request,
        CampaignService $campaignService,
        array $selectedFields,
        $entriesPerPage,
        $pageNo
    ) {
        $query = (new ServiceQueryBuilder())
            ->select($selectedFields)
            ->orderByAsc('Name')
            ->limit(
                ($pageNo - 1) * $entriesPerPage,
                intval($entriesPerPage)
            )->build();

        $totalNumEntries = 0;
        $results = [];

        $page = $campaignService->query("$query");
        if (!empty($page->getEntries())) {
            $totalNumEntries = $page->getTotalNumEntries();
            $results = $page->getEntries();
        }

        $request->session()->put('totalNumEntries', $totalNumEntries);

        return collect($results);
    }
    public static function getConversionsFirstLevelGroup($productGroups, $criterionId) {
        return 
        [
            "PGL1G1CVR" => $AdgCVR,
            "PGL1G1N"   => $N,
            "PGL1G1AOV" => $AdgAOV,
        ];
    }

    private static function changeBidForCriterionId($criterionId, $adGroupId) {
        $adWordsServices = new AdWordsServices();
        $session = $this->getSession();
        $adGroupCriterionService = $adWordsServices->get($session, AdGroupCriterionService::class);
        $operations = [];
        $adGroupCriterion = new BiddableAdGroupCriterion();
        // $adGroupCriterion->setAdGroupId(22622716325); // id of my adgroup
        // $adGroupCriterion->setCriterion(new Criterion(300519082732)); // id of partition group. you can get find this id in PRODUCT_PARTITION_REPORT in ID field (which full name is Criterion ID)
        $adGroupCriterion->setAdGroupId($adGroupId); // id of my adgroup
        $adGroupCriterion->setCriterion(new Criterion($criterionId)); // id of partition group. you can get find this id in PRODUCT_PARTITION_REPORT in ID field (which full name is Criterion ID)
        //
        $bid = new CpcBid();
        $money = new Money();
        $money->setMicroAmount(((float)4)*1000000);
        $bid->setBid($money);
        $biddingStrategyConfiguration = new BiddingStrategyConfiguration();
        $biddingStrategyConfiguration->setBids([$bid]);
        $adGroupCriterion->setBiddingStrategyConfiguration($biddingStrategyConfiguration);
        $operation = new AdGroupCriterionOperation();
        $operation->setOperand($adGroupCriterion);
        $operation->setOperator(Operator::SET);
        $operations[] = $operation;
        //
        $adGroupCriterionService->mutate($operations);
    
    }

    // =====================================================================================================================    
    /**
     * runBidLandscapes
     *
     * @return void
     */
    public static function runBidLandscapes(
        AdWordsServices $adWordsServices,
        AdWordsSession $session,
        array $adGroup,
        array $productGroups,
        array $campaigns
    ) {
        echo "start<br>";
        
        $adGroupId               = $adGroup["adGroupID"]      ?? 0;
        // if ($adGroupId != "76630612506") return false;
        // if ($adGroupId != "53251615365") return false;
        // if ($adGroupId != "85438336468") return false;
        if ($adGroupId != "38163596112") return false; 
  
        $dataService = $adWordsServices->get($session, DataService::class);

        $campaignId = $adGroup["campaignID"] ?? 0;       
        if (!$campaignId) die("something went wrong: no campaign found");
        extract($campaigns[$campaignId]);

        /*
        Next calculate conversion rates for all adgroups within the campaign.
        - If adgroup clicks<N set AdgCVR = CamCVR
        - If there are no conversions but clicks>=N, set AdgCVR=CamCVR-2*sqrt(CamCVR*(1-CamCVR)/AdgClicks)
        - If conversions>0 and clicks>N set AdgCVR=AdgConversions/AdgClicks
        - If AdgClicks<N keep N as it is, If AdgClicks>N reset N to:
        - N=AdgCVR*(1-AdgCVR)*4/(AdgCVR**2)
        */
        $AdgClicks               = $adGroup["clicks"]         ?? 0;
        $AdgConversions          = $adGroup["conversions"]    ?? 0;
        $AdgTotalConversionValue = $adGroup["totalConvValue"] ?? 0;
        $adGroupId               = $adGroup["adGroupID"]      ?? 0;
        
        if (!$adGroupId) return false;
        
        $AdgCVR = 0;
        if ($AdgClicks <= $N) {
            $AdgCVR = $CamCVR;
        } else if (($AdgConversions == 0) && ($AdgClicks > $N)) {
            // - If there are no conversions but clicks>=N, set AdgCVR=CamCVR-2*sqrt(CamCVR*(1-CamCVR)/AdgClicks)
            $AdgCVR = $CamCVR - 2 * sqrt($CamCVR * (1 - $CamCVR)/ $AdgClicks);
        } else if ($AdgConversions && ($AdgClicks > $N)) {
            // - If conversions>0 and clicks>N set AdgCVR=AdgConversions/AdgClicks
            $AdgCVR = $AdgConversions / $AdgClicks;
            // if ($adGroupId == "54308388945") {
            //     echo "AdgCVR has been calculated again as $AdgCVR \n";
            // }
        }

        // - If AdgClicks<N keep N as it is, If AdgClicks>N reset N to:
        if ($AdgClicks > $N) {
            $N = $AdgCVR ? ($AdgCVR * (1 - $AdgCVR) * 4 / ($AdgCVR ** 2)) : 0;
        }

        // For average order value:
        // - AdgAOV=CamAOV if AdgConversions<2
        if ($AdgConversions < 2) {
            $AdgAOV = $CamAOV ;
        } else {
            // - AdgAOV=AdgTotalConversionValue/AdgConversions if AdgConversions>=2
            $AdgAOV = $AdgTotalConversionValue / $AdgConversions;
        }

        // Create a query to select all keyword bid simulations for the
        // specified ad group.
        $query = (new ServiceQueryBuilder())
            ->select([
                'AdGroupId',
                'CriterionId',
                'StartDate',
                'EndDate',
                'Bid',
                'BiddableConversions',
                'BiddableConversionsValue',
                'LocalClicks',
                'LocalCost',
                'LocalImpressions'
            ])
            ->where('AdGroupId')->in( [$adGroupId] )
            ->limit(0, 100)
            ->build();


        $dataRows = [];
        // Display bid landscapes.
        do {
            if (isset($fetchedPage)) {
                // Advance the paging offset in subsequent iterations only.
                $query->nextPage();
            } else {
                // echo "no page fetched<br>";
            }


            // Retrieve keyword bid simulations one page at a time, continuing
            // to request pages until all of them have been retrieved.
            $fetchedPage = $dataService->queryCriterionBidLandscape(
                sprintf('%s', $query)
            );

            // Print out some information for each bid landscape.
            if ($fetchedPage->getEntries() !== null) {
                foreach ($fetchedPage->getEntries() as $bidLandscape) {
                    printf(
                        "Found a criterion bid landscape with ad group ID %d," .
                        " criterion ID %d, start date '%s', end date '%s'," .
                        " and landscape points:%s",
                        $bidLandscape->getAdGroupId(),
                        $bidLandscape->getCriterionId(),
                        $bidLandscape->getStartDate(),
                        $bidLandscape->getEndDate(),
                        PHP_EOL
                    );
                    foreach ($bidLandscape->getLandscapePoints() as
                             $bidLandscapePoint) {
                            
                        $criterionId       = $bidLandscape->getCriterionId();
                        $parentCriterionId = $productGroups[$criterionId]["parentCriterionID"] ?? 0;
                        $productGroupName  = $productGroups[$criterionId]["productGroup"]      ?? "";
                        $parentGroup       = $productGroups[$parentCriterionId]["productGroup"] ?? "";
                        $PGL1G1CVR = 0;
                        $PGL1G1N   = 0;
                        $PGL1G1AOV = 0;

                        $row = [
                            "CamCVR"                   => $CamCVR,
                            "CamAOV"                   => $CamAOV,
                            "AdgCVR"                   => $AdgCVR,
                            "N"                        => $N,
                            "AdgAOV"                   => $AdgAOV,
                            
                            "productGroup"             => $productGroupName,
                            "productGroupCpcBid"       => self::conv($productGroups[$criterionId]["maxCPC"] ?? 0),
                            "productGroupImpressions"  => $productGroups[$criterionId]["impressions"]       ?? "",
                            "productGroupClicks"       => $productGroups[$criterionId]["clicks"]            ?? "",
                            "productGroupCost"         => self::conv($productGroups[$criterionId]["cost"]   ?? 0),
                            "productGroupConversions"  => $productGroups[$criterionId]["conversions"]       ?? "",
                            "productGroupConvValue"    => $productGroups[$criterionId]["totalConvValue"]    ?? 0,

                            "PGL1G1CVR"                => $PGL1G1CVR,
                            "PGL1G1N"                  => $PGL1G1N,
                            "PGL1G1AOV"                => $PGL1G1AOV,

                            "parentGroup"              => $parentGroup,
                            "parentGroupCpcBid"        => self::conv($productGroups[$parentCriterionId]["maxCPC"] ?? 0),
                            "parentGroupImpressions"   => $productGroups[$parentCriterionId]["impressions"]       ?? "",
                            "parentGroupClicks"        => $productGroups[$parentCriterionId]["clicks"]            ?? "",
                            "parentGroupCost"          => self::conv($productGroups[$parentCriterionId]["cost"]   ?? 0),
                            "parentGroupConversions"   => $productGroups[$parentCriterionId]["conversions"]       ?? "",
                            "parentGroupConvValue"     => $productGroups[$parentCriterionId]["totalConvValue"]    ?? 0,

                            "adGroupId"                => $bidLandscape->getAdGroupId(),
                            "criterionId"              => $criterionId,
                            "parentCriterionId"        => $parentCriterionId,
                            "bidStartDate"             => $bidLandscape->getStartDate(),
                            "bidEndDate"               => $bidLandscape->getEndDate(),
                            "bidClicks"                => $bidLandscapePoint->getClicks(),
                            "bid"                      => self::conv($bidLandscapePoint->getBid()->getMicroAmount()),
                            "bidCost"                  => self::conv($bidLandscapePoint->getCost()->getMicroAmount()),
                            "bidImpressions"           => $bidLandscapePoint->getImpressions(),
                            "biddableConversions"      => $bidLandscapePoint->getBiddableConversions(),
                            "biddableConversionsValue" => self::conv($bidLandscapePoint->getBiddableConversionsValue()),

                            "margin"                   => self::MARGIN,
                            "profit"                   => 0,
                            "bidWithin15"              => "FALSE",
                            "optBid"                   => 0,
                        ];

                        $dataRows[] = array_merge($adGroup, $row);
                    }
                    print PHP_EOL;
                }
            } else {
                echo "the fetched page is null<br>";
            }
        } while ($query->hasNext($fetchedPage));

        $dataByCriterion  = [];
        $firstLevelGroups = [];
        $justCriterionIds = [];
        if (sizeof($dataRows)) {
            $firstLevelGroups = array_filter($dataRows, function($arr) use (&$dataByCriterion) {
                if ($arr["parentGroup"] == "* /") {
                    $dataByCriterion[$arr["criterionId"]] = $arr;
                    return true;
                } 
            });
            $justCriterionIds =  array_unique(array_column($firstLevelGroups, "productGroup", "criterionId"));            

            $pGValues = [];
            if (sizeof($justCriterionIds) == 1) {
                // just one product level group
                $criterionId = array_key_first($justCriterionIds);
                $pGValues[$criterionId] = [
                    "PGL1G1CVR" => $AdgCVR,
                    "PGL1G1N"   => $N,
                    "PGL1G1AOV" => $AdgAOV,
                ];
            } else {
                foreach($justCriterionIds as $criterionId => $groupName) {
                    $pGValues[$criterionId] = 
                        self::calculatePGValues($dataByCriterion[$criterionId], $N, $AdgCVR, $AdgAOV);
                }                
            }

            self::buildTree ($justCriterionIds, $dataRows, $pGValues );

            $lines = [];
            foreach ($dataRows as $index => $row) {
                $criterionId = $row["criterionId"];
                $rowToAdd    = $row;
                if (isset($pGValues[$criterionId])) {
                    $rowToAdd = array_merge($row, $pGValues[$criterionId]);
                }
                // calculate profit and check if bid is within 15%
                $rowToAdd["profit"]      = self::calculateProfit($rowToAdd);
                $rowToAdd["bidWithin15"] = self::isbidWithin15($rowToAdd);

                $lines[] = $rowToAdd;
            }

            // to find the optimum bid first group rows by $criterionId
            $linesByCriterion = [];
            foreach ($lines as $line) {
                $criterionId = $line["criterionId"];
                $linesByCriterion[$criterionId][] = $line;
            }
            
            //find the optimum bid
            foreach ($linesByCriterion as $criterionId => $lines) {
                $optBidIndex = -1;
                $optProfit   = 0;
                foreach ($lines as $index => $line) {
                    if ($line["bidWithin15"] == "TRUE") {
                        if ($line["profit"] > $optProfit) {
                            $optProfit   = $line["profit"];
                            $optBidIndex = $index;
                        }
                    }
                }
                if ($optBidIndex > -1) {
                    $curBid = $linesByCriterion[$criterionId][$optBidIndex]["productGroupCpcBid"];
                    $optBid = $linesByCriterion[$criterionId][$optBidIndex]["bid"];
                    
                    // Refinements
                    // If profit next bid up > profit opt bid, set opt bid at 1.15*current bid
                    $profitNextBidUp = $linesByCriterion[$criterionId][$optBidIndex + 1] ?? 0;
                    if ($profitNextBidUp > $optProfit) {
                        $optBid = 1.15 * $curBid;
                    } else {
                        // if current bid >opt bid and (current bid-opt bid)/(next bid up-current bid)*(profit opt bid-profit next bid up)/profit opt bid<0.02
                        if (($curBid > $optBid) && 
                            ((($curBid - $optBid) / ($profitNextBidUp - $curBid) * ($optProfit - $profitNextBidUp) / $optProfit) < 0.02 )) {
                            $optBid = $curBid;
                        }
                    }

                    $linesByCriterion[$criterionId][$optBidIndex]["optBid"] = $optBid;
                }
            }

            
            // echo "LINES by criterion:\n";
            // print_r($linesByCriterion);
            // exit;

            $fp = fopen('./file.csv', 'a');
            foreach ($linesByCriterion as $criterionId => $lines) {
                foreach ($lines as $index => $row) {
                    // if (!$index) fputcsv($fp, array_keys($row)); //header row
                    fputcsv($fp, array_values($row));
                }        
            }
            fclose($fp);
        }
    }
    private static function isbidWithin15 ($dataRow) {
        $bid         = $dataRow["bid"]                ?? 0;
        $curBid      = $dataRow["productGroupCpcBid"] ?? 0;
        $bidWithin15 = "FALSE";
        if ($curBid) {
            if (abs($bid - $curBid) < (0.15 * $curBid)) {
                $bidWithin15 = "TRUE";
            }
        }
        
        return $bidWithin15;
    }

    private static function calculateProfit($dataRow) {
        // Profit=Bid Clicks * PGL1G1CVR * PGL1G1AOV * Margin - Bid Cost
        $bidClicks = $dataRow["bidClicks"] ?? 0;
        $bidCost   = $dataRow["bidCost"]   ?? 0;
        $PGL1G1CVR = $dataRow["PGL1G1CVR"] ?? 0;
        $PGL1G1AOV = $dataRow["PGL1G1AOV"] ?? 0;
        $margin    = $dataRow["margin"]    ?? 0;

        $profit    = $bidClicks * $PGL1G1CVR * $PGL1G1AOV * $margin / 100 - $bidCost;

        return $profit;
    }

    private static function buildTree ($parentIds, &$dataRows, &$pGValues ) {
        foreach ($parentIds as $parentCriterionId => $parentGroupName) {
            $dataByCriterion = [];
            $childrenRows = array_filter($dataRows, function($arr) use (&$dataByCriterion, $parentGroupName) {
                if ($arr["parentGroup"] == $parentGroupName) {
                    $dataByCriterion[$arr["criterionId"]] = $arr;
                    return true;
                } 
            });
            $children  =  array_unique(array_column($childrenRows, "productGroup", "criterionId"));
            foreach($children as $criterionId => $GroupName) {
                if (isset($pGValues[$parentCriterionId])) {
                    extract($pGValues[$parentCriterionId]);
                    $pGValues[$criterionId] = 
                        self::calculatePGValues($dataByCriterion[$criterionId], $PGL1G1N, $PGL1G1CVR, $PGL1G1AOV);                
                } else echo "Error with getting pGValues for parentCriterionId:[$parentCriterionId]\n";
            }
            self::buildTree ($children, $dataRows, $pGValues );
        }
    }

    private static function calculatePGValues($grp, $N, $AdgCVR, $AdgAOV) {
        $clicks         = $grp["productGroupClicks"]      ?? 0;
        $conversions    = $grp["productGroupConversions"] ?? 0;
        $totalConvValue = $grp["productGroupConvValue"]   ?? 0;
        $PGL1GiCVR      = 0;
        $PGL1GiN        = $N;
        
        // If PGL1GiClicks<N: PGL1GiCVR=AdgCVR
        if ($clicks <= $N) {
            $PGL1GiCVR = $AdgCVR;
        } else if (($conversions == 0) && $clicks > $N) {
            // If PGL1GiConversions=0 but PGL1GiClicks>N
            // PGL1GiCVR=AdgCVR-2*sqrt(AdgCVR*(1-AdgCVR)/PGL1GiClicks)
            $PGL1GiCVR = $AdgCVR - 2 * sqrt($AdgCVR * (1 - $AdgCVR) / $clicks);
        } else if (($conversions > 0) && ($clicks > $N)) {
            // If PGL1GiConversions>0 and PGL1GiClicks>N:
            $PGL1GiCVR = $conversions / $clicks;
        }
        // If PGL1GiClicks<N keep N as it is, If PGL1GiClicks>N reset N to:
        if ($clicks > $N) {
            // N=PGL1GiCVR*(1-PGL1GiCVR)*4/(PGL1GiCVR**2)
            $PGL1GiN = $PGL1GiCVR * (1 - $PGL1GiCVR) * 4 / ($PGL1GiCVR ** 2);
        }

        // For average order value:
        if ($conversions < 2) {
            $PGL1GiAOV = $AdgAOV;
        } else {
            // PGL1GiAOV=PGL1GiTotalConversionValue/PGL1GiConversions if PGL!GiConversions>=2
            $PGL1GiAOV = $totalConvValue / $conversions;
        }
        return [
            "PGL1G1CVR" => $PGL1GiCVR,
            "PGL1G1N"   => $PGL1GiN,
            "PGL1G1AOV" => $PGL1GiAOV
        ];
    }

    public static function printCsvHeader() {
        $headers = [
            "AdGroupId",
            "AdGroup",
            "AdGroupType",
            "AdGroupState",
            "CampaignId",
            "Impressions",
            "Clicks",
            "CTR",
            "Cost",
            "Default Max CPC",
            "Conversions",
            "TotalConvValue",

            "CamCVR",
            "CamAOV",
            "AdgCVR",
            "N",
            "AdgAOV",

            "ProductGroup",
            "ProductGroup MaxCPC",
            "ProductGroup Impressions",
            "ProductGroup Clicks",
            "ProductGroup Cost",
            "ProductGroup Conversions",
            "ProductGroup ConvValue",

            "PGL1G1CVR",
            "PGL1G1N",
            "PGL1G1AOV",

            "ParentGroup",
            "ParentGroup CpcBid",
            "ParentGroup Impressions",
            "ParentGroup Clicks",
            "ParentGroup Cost",
            "ParentGroup Conversions",
            "ParentGroup ConvValue",

            "AdGroupId",
            "CriterionId",
            "Parent CriterionId",
            "Bid Start Date",
            "Bid End Date",
            "Bid Clicks",
            "Bid",
            "Bid Cost",
            "Bid Impressions",
            "Biddable Conversions",
            "Biddable Conversions Value",

            "Margin",
            "Profit",
            "Bid within 15%",
            "Opt bid",
        ];
        $fp = fopen('./file.csv', 'a');
            fputcsv($fp, $headers); //header row
        fclose($fp);
    }

    public static function conv($val) {
        $val = (float) $val;
        return $val / 1000000;
    }
    
        /**
     * Fetch campaigns using the provided campaign service, selected fields, the
     * number of entries per page and the specified page number.
     *
     * @param Request $request
     * @param CampaignService $campaignService
     * @param string[] $selectedFields
     * @param int $entriesPerPage
     * @param int $pageNo
     * @return Collection
     */
    private function fetchBidLandscapes(
        Request $request,
        DataService $campaignService,
        array $selectedFields,
        $entriesPerPage,
        $pageNo,
        $oAuth2Credential,
        $adWordsServices
    ) {

        // TEST
        $session = (new AdWordsSessionBuilder())
        ->fromFile(config('app.adsapi_php_path'))
            ->withOAuth2Credential($oAuth2Credential)
            ->withClientCustomerId("766-323-4537")
            ->build();

        $campaigns        = $this->getCampaigns($session);
        $productGroups    = $this->getProductPartitions($session);
        $shoppingAdGroups = $this->getShoppingAdGroups($session);

        self::printCsvHeader();
        set_time_limit (600);
        ini_set('soap.wsdl_cache_enabled',0);
        ini_set('soap.wsdl_cache_ttl',0);        

        $c = 0 ;
        foreach ($shoppingAdGroups as $adGroup) {
            // if (($c < 300) && ($c > 1)) {
                // print_r($adGroup);
                self::runBidLandscapes($adWordsServices, $session, $adGroup, $productGroups, $campaigns);
                echo "done -$c<hr>";
            // }
            $c++;
        }
        
        exit;


        $dataService = $adWordsServices->get($session, DataService::class);

        // Create a query to select all keyword bid simulations for the
        // specified ad group.
        $query = (new ServiceQueryBuilder())
            ->select([
                'AdGroupId',
                'CriterionId',
                'StartDate',
                'EndDate',
                'Bid',
                'BiddableConversions',
                'BiddableConversionsValue',
                'LocalClicks',
                'LocalCost',
                'LocalImpressions'
            ])
            ->where('AdGroupId')->in([1376502367])
            ->limit(0, 20)
            ->build();
        
        $fetchedPage = $dataService->queryCriterionBidLandscape(
            sprintf('%s', $query)
        );
        echo "here<hr>";

        // Display bid landscapes.
        do {
            if (isset($fetchedPage)) {
                // Advance the paging offset in subsequent iterations only.
                $query->nextPage();
            }

            // Retrieve keyword bid simulations one page at a time, continuing
            // to request pages until all of them have been retrieved.
            $fetchedPage = $dataService->queryCriterionBidLandscape(
                sprintf('%s', $query)
            );

            // Print out some information for each bid landscape.
            if ($fetchedPage->getEntries() !== null) {
                foreach ($fetchedPage->getEntries() as $bidLandscape) {
                    printf(
                        "Found a criterion bid landscape with ad group ID %d," .
                        " criterion ID %d, start date '%s', end date '%s'," .
                        " and landscape points:%s",
                        $bidLandscape->getAdGroupId(),
                        $bidLandscape->getCriterionId(),
                        $bidLandscape->getStartDate(),
                        $bidLandscape->getEndDate(),
                        PHP_EOL
                    );
                    foreach ($bidLandscape->getLandscapePoints() as
                            $bidLandscapePoint) {
                        printf(
                            "  bid: %d => clicks: %d, cost: %d, impressions: %d"
                            . ", biddable conversions: %.2f, biddable "
                            . "conversions value: %.2f%s",
                            $bidLandscapePoint->getBid()->getMicroAmount(),
                            $bidLandscapePoint->getClicks(),
                            $bidLandscapePoint->getCost()->getMicroAmount(),
                            $bidLandscapePoint->getImpressions(),
                            $bidLandscapePoint->getBiddableConversions(),
                            $bidLandscapePoint->getBiddableConversionsValue(),
                            PHP_EOL
                        );
                    }
                    print PHP_EOL;
                }
            }
        } while ($query->hasNext($fetchedPage));

        echo "<bR>here END <hr>";

        exit;
        // END TEST
        
        

        //             ->select($selectedFields)
        $query = (new ServiceQueryBuilder())
        ->select([
            'AdGroupId',
            'CriterionId',
            'StartDate',
            'EndDate',
            'Bid',
            'BiddableConversions',
            'BiddableConversionsValue',
            'LocalClicks',
            'LocalCost',
            'LocalImpressions'
        ])
        ->where('AdGroupId')->in(["1376502367","692610372","1441578364","266962011","1376799753","1376842035"])
        ->limit(
            ($pageNo - 1) * $entriesPerPage,
            intval($entriesPerPage)
        )->build();

        $totalNumEntries = 0;
        $results = [];

        // $page = $campaignService->queryCriterionBidLandscape("$query");
        echo "here";
        $fetchedPage = $campaignService->queryCriterionBidLandscape(
            sprintf('%s', $query)
        );        

        // Print out some information for each bid landscape.
        if ($fetchedPage->getEntries() !== null) {
            foreach ($fetchedPage->getEntries() as $bidLandscape) {
                printf(
                    "Found a criterion bid landscape with ad group ID %d," .
                    " criterion ID %d, start date '%s', end date '%s'," .
                    " and landscape points:%s",
                    $bidLandscape->getAdGroupId(),
                    $bidLandscape->getCriterionId(),
                    $bidLandscape->getStartDate(),
                    $bidLandscape->getEndDate(),
                    PHP_EOL
                );
                foreach ($bidLandscape->getLandscapePoints() as
                        $bidLandscapePoint) {
                    printf(
                        "  bid: %d => clicks: %d, cost: %d, impressions: %d"
                        . ", biddable conversions: %.2f, biddable "
                        . "conversions value: %.2f%s",
                        $bidLandscapePoint->getBid()->getMicroAmount(),
                        $bidLandscapePoint->getClicks(),
                        $bidLandscapePoint->getCost()->getMicroAmount(),
                        $bidLandscapePoint->getImpressions(),
                        $bidLandscapePoint->getBiddableConversions(),
                        $bidLandscapePoint->getBiddableConversionsValue(),
                        PHP_EOL
                    );
                }
                print PHP_EOL;
            }
        } else {
            echo "<br>it's null";
        }


        exit;

        if (!empty($page->getEntries())) {
            $totalNumEntries = $page->getTotalNumEntries();
            $results = $page->getEntries();
        }

        $request->session()->put('totalNumEntries', $totalNumEntries);

        return collect($results);
    }

    /**
     * Controls a POST and GET request that is submitted from the "Download
     * Report" form.
     *
     * @param Request $request
     * @param FetchAuthTokenInterface $oAuth2Credential
     * @param AdWordsServices $adWordsServices
     * @param AdWordsSessionBuilder $adWordsSessionBuilder
     * @return View
     */
    public function downloadReportAction(
        Request $request,
        FetchAuthTokenInterface $oAuth2Credential,
        AdWordsServices $adWordsServices,
        AdWordsSessionBuilder $adWordsSessionBuilder
    ) {
        if ($request->method() === 'POST') {
            $clientCustomerId = $request->input('clientCustomerId');
            $reportType = $request->input('reportType');
            $reportRange = $request->input('reportRange');
            $entriesPerPage = $request->input('entriesPerPage');

            $selectedFields = array_values(
                $request->except(
                    [
                        '_token',
                        'clientCustomerId',
                        'reportType',
                        'entriesPerPage',
                        'reportRange'
                    ]
                )
            );
            $selectedFields = array_merge(
                self::$REPORT_TYPE_TO_DEFAULT_SELECTED_FIELDS[$reportType],
                $selectedFields
            );

            $request->session()->put('clientCustomerId', $clientCustomerId);
            $request->session()->put('reportType', $reportType);
            $request->session()->put('reportRange', $reportRange);
            $request->session()->put('selectedFields', $selectedFields);
            $request->session()->put('entriesPerPage', $entriesPerPage);

            // Construct an API session configured from a properties file and
            // the OAuth2 credentials above.
            $session =
                $adWordsSessionBuilder->fromFile(config('app.adsapi_php_path'))
                    ->withOAuth2Credential($oAuth2Credential)
                    ->withClientCustomerId($clientCustomerId)
                    ->build();

            // There is no paging mechanism for reporting, so we fetch all
            // results at once.
            $collection = self::downloadReport(
                $reportType,
                $reportRange,
                new ReportDownloader($session),
                $selectedFields
            );
            $request->session()->put('collection', $collection);
        } else {
            $selectedFields = $request->session()->get('selectedFields');
            $entriesPerPage = $request->session()->get('entriesPerPage');
            $collection = $request->session()->get('collection');
        }

        $pageNo = $request->input('page') ?: 1;

        // Create a length aware paginator to supply report results for the
        // view, based on the specified number of entries per page.
        $reportResults = new LengthAwarePaginator(
            $collection->forPage($pageNo, $entriesPerPage),
            $collection->count(),
            $entriesPerPage,
            $pageNo,
            ['path' => url('download-report')]
        );

        return view(
            'report-results',
            compact('reportResults', 'selectedFields')
        );
    }

    public function getProductPartitions(
        $session
    ) {
        $reportType       = "PRODUCT_PARTITION_REPORT";
        $reportRange      = "LAST_30_DAYS"; //"YESTERDAY";
        $selectedFields   = self::$REPORT_TYPE_TO_DEFAULT_SELECTED_FIELDS[$reportType];

        // There is no paging mechanism for reporting, so we fetch all
        // results at once.
        $collection = $this->downloadReport(
            $reportType,
            $reportRange,
            new ReportDownloader($session),
            $selectedFields
        );

        $productGroups = [];
        foreach ($collection as $element) {
            $a                           = $element["@attributes"] ?? [];

            $criterionId                 = $a["criterionID"]  ?? "";
            $productGroups[$criterionId] = $a;
            // if ($a["impressions"] == 2899) {
                echo "product group\n";
                print_r($a);
                // exit;
            // }
        }
        return $productGroups;

    }

    public function getCampaigns ($session) {
        $reportType       = "CAMPAIGN_PERFORMANCE_REPORT";
        $reportRange      = "LAST_30_DAYS"; //"YESTERDAY";
        $selectedFields   = self::$REPORT_TYPE_TO_DEFAULT_SELECTED_FIELDS[$reportType];

        // There is no paging mechanism for reporting, so we fetch all
        // results at once.
        $collection = self::downloadReport(
            $reportType,
            $reportRange,
            new ReportDownloader($session),
            $selectedFields
        );

        $campaigns = [];
        foreach ($collection as $element) {
            $a              = $element["@attributes"] ?? [];
            $campaignId     = $a["campaignID"]  ?? "";
            $conversions    = $a["conversions"] ?? 0;
            $clicks         = $a["clicks"] ?? 0;
            $totalConvValue = $a["totalConvValue"] ?? 0;

            // CamCVR (CampaignConversion rate): sum(conversions)/sum(clicks)
            $CamCVR = ($clicks > 0 )     ? ($conversions / $clicks) : 0;
            // CamAOV (CampaignAverage order value): total conversion value/sum(conversions)
            $CamAOV = ((float)$conversions > 0) ? ($totalConvValue / (float)$conversions) : 0;
            // Calculate N=CamCVR*(1-CamCVR)*4/(CamCVR**2)
            $N = $CamCVR ? ($CamCVR * (1 - $CamCVR) * 4 / ($CamCVR ** 2)) : 0;

            $data = [
                "campaign"          => $a["campaign"]    ?? "",
                "CamImpressions"    => $a["impressions"] ?? 0,
                "CamClicks"         => $clicks,
                "CamCtr"            => $a["ctr"]  ?? 0,
                "CamCost"           => $a["cost"] ?? 0,
                "CamTotalConvValue" => $totalConvValue,
                "CamCVR"            => $CamCVR,
                "CamAOV"            => $CamAOV,
                "N"                 => $N,
            ];

            $campaigns[$campaignId] = $data;
        }
        // print_r($campaigns);
        // exit;
        return $campaigns;
    }

/**
     * Controls a POST and GET request that is submitted from the "Download
     * Report" form.
     *
     * @param Request $request
     * @param FetchAuthTokenInterface $oAuth2Credential
     * @param AdWordsServices $adWordsServices
     * @param AdWordsSessionBuilder $adWordsSessionBuilder
     * @return View
     */
    public function getShoppingAdGroups(
        $session
    ) {
        $reportType       = "ADGROUP_PERFORMANCE_REPORT";
        $reportRange      = "LAST_30_DAYS";
        $selectedFields   = self::$REPORT_TYPE_TO_DEFAULT_SELECTED_FIELDS[$reportType];

        // There is no paging mechanism for reporting, so we fetch all
        // results at once.
        $collection = self::downloadReport(
            $reportType,
            $reportRange,
            new ReportDownloader($session),
            $selectedFields
        );

        $shoppingAdGroups = [];
        foreach ($collection as $el) {
            $a  = $el["@attributes"];
            $id = $a["adGroupID"];
            if (($a["adGroupType"] == "Shopping - Product") && ($a["adGroupState"] == "enabled")) {
                $shoppingAdGroups[$id] = [
                    "adGroupID"      => $a["adGroupID"],
                    "adGroup"        => $a["adGroup"],
                    "adGroupType"    => $a["adGroupType"],
                    "adGroupState"   => $a["adGroupState"],
                    "campaignID"     => $a["campaignID"],
                    "impressions"    => $a["impressions"],
                    "clicks"         => $a["clicks"],
                    "ctr"            => $a["ctr"],
                    "cost"           => self::conv($a["cost"]),
                    "defaultMaxCPC"  => self::conv($a["defaultMaxCPC"]),
                    "conversions"    => $a["conversions"],
                    "totalConvValue" => $a["totalConvValue"]
                ];
            }
        }

        // print_r($shoppingAdGroups);
        // $pageNo = 1;
        // exit;
        return $shoppingAdGroups;

        // Create a length aware paginator to supply report results for the
        // view, based on the specified number of entries per page.
        // $reportResults = new LengthAwarePaginator(
        //     $collection->forPage($pageNo, $entriesPerPage),
        //     $collection->count(),
        //     $entriesPerPage,
        //     $pageNo,
        //     ['path' => url('download-report')]
        // );

        // return view(
        //     'report-results',
        //     compact('reportResults', 'selectedFields')
        // );
    }




    /**
     * Download a report of the specified report type and date range, selected
     * fields, and the number of entries per page.
     *
     * @param string $reportType
     * @param string $reportRange
     * @param ReportDownloader $reportDownloader
     * @param string[] $selectedFields
     * @return Collection
     */
    private function downloadReport(
        $reportType,
        $reportRange,
        ReportDownloader $reportDownloader,
        array $selectedFields
    ) {
        $query = (new ReportQueryBuilder())
            ->select($selectedFields)
            ->from($reportType)
            ->duringDateRange($reportRange)->build();

        // For brevity, this sample app always excludes zero-impression rows.
        $reportSettingsOverride = (new ReportSettingsBuilder())
            ->includeZeroImpressions(false)
            ->build();
        $reportDownloadResult = $reportDownloader->downloadReportWithAwql(
            "$query",
            DownloadFormat::XML,
            $reportSettingsOverride
        );

        $json = json_encode(
            simplexml_load_string($reportDownloadResult->getAsString())
        );
        $resultTable = json_decode($json, true)['table'];

        if (array_key_exists('row', $resultTable)) {
            // When there is only one row, PHP decodes it by automatically
            // removing the containing array. We need to add it back, so the
            // "view" can render this data properly.
            $row = $resultTable['row'];
            $row = count($row) > 1 ? $row : [$row];
            return collect($row);
        }

        // No results returned for this query.
        return collect([]);
    }
}
