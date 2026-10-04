<?php

namespace Database\Seeders\Pages;

use App\Models\Page\Page;
use Illuminate\Database\Seeder;

class PagesSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {

        $pages = [
            [
                'title' => 'Privacy Policy',
                'slug' => 'privacy-gdpr',
            ],
            [
                'title' => 'Terms of Service',
                'slug' => 'terms',
            ],
            [
                'title' => 'Risk Disclosure Statement',
                'slug' => 'disclosure',
            ],
            [
                'title' => 'Support Center',
                'slug' => 'support-center',
            ],
            [
                'title' => 'KYC Verification',
                'slug' => 'kyc',
            ],
            [
                'title' => 'Referrals',
                'slug' => 'referrals',
            ],
            [
                'title' => 'Fees',
                'slug' => 'fees',
            ],
            [
                'title' => 'Trading Rules',
                'slug' => 'trading-rules',
            ],
            [
                'title' => 'Contacts',
                'slug' => 'contacts',
            ],
            [
                'title' => 'About',
                'slug' => 'about',
            ],
            [
                'title' => 'P2P Help Center',
                'slug' => 'p2p-help-center',
            ]
        ];


        foreach ($pages as $page) {

            $model = Page::whereSlug($page['slug'])->first();

            if(!$model) {
                $pageModel = new Page();
                $pageModel->title = $page['title'];
                $pageModel->slug = $page['slug'];
                $pageModel->content = 'Edit this content';
                $pageModel->status = true;
                $pageModel->save();
            }
        }

    }
}
