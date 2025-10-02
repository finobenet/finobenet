<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('something', function () {
    $db = DB::connection('finobe');

    /* purchases type rewrite
    $purchases = $this->db->table('purchases')
        ->get()
        ->map(function ($item) {
            return (array) $item;
        })->toArray();
    
    foreach($purchases as $purchase) {
        if($purchase['assetid'] == 0 && $purchase['amount'] == -625) {
            $this->db->table('purchases')
                ->where('id', $purchase['id'])
                ->update([
                    'type' => 3
                ]);
        } elseif($purchase['assetid'] != 0 && $purchase['amount'] <= 0) {
            $this->db->table('purchases')
                ->where('id', $purchase['id'])
                ->update([
                    'type' => 1
                ]);
        } elseif($purchase['assetid'] == 0 && $purchase['amount'] > 0) {
            $this->db->table('purchases')
                ->where('id', $purchase['id'])
                ->update([
                    'type' => 4
                ]);
        }
    }
    */
    
    /* messages id rewrite
    $messages = $this->db->table('messages')
        ->get();
    
    foreach($messages as $message) {
        if(!User::where('username', $message->author)->exists()) {
            continue;
        }
        
        $this->db->table('messages')
            ->where('id', $message->id)
            ->update([
                'author' => User::where('username', $message->author)->value('id'),
                'touser' => User::where('username', $message->touser)->value('id')
            ]);
    }
    */

    /* pms id rewrite
    $pms = $this->db->table('pms')
        ->get();
    
    foreach($pms as $pm) {
        if(!User::where('username', $pm->touser)->exists()) {
            continue;
        }
        
        $this->db->table('pms')
            ->where('id', $pm->id)
            ->update([
                'touser' => User::where('username', $pm->touser)->value('id'),
                'owner' => User::where('username', $pm->owner)->value('id')
            ]);
    }
    */

    /* friends userid rewrite
    $users = User::all();
    $usernameSet = [];

    foreach ($users as $user) {
        $friends = json_decode($user->friends, true) ?? [];

        foreach ($friends as $friend) {
            if (!isset($friend['userid']) && isset($friend['username'])) {
                $usernameSet[] = $friend['username'];
            }
        }
    }

    $usernameSet = array_unique($usernameSet);
    $userIds = User::whereIn('username', $usernameSet)->pluck('id', 'username')->toArray();

    foreach ($users as $user) {
        $friends = json_decode($user->friends, true) ?? [];
        $modified = false;

        foreach ($friends as $key => $friend) {
            if (!isset($friend['userid']) && isset($friend['username'])) {
                $username = $friend['username'];
                if (isset($userIds[$username])) {
                    $friends[$key]['userid'] = $userIds[$username];
                    unset($friends[$key]['username']);
                    $modified = true;
                }
            }
        }

        if ($modified) {
            $user->friends = json_encode($friends);
            $user->save();
        }
    }
    */

    /* remove friends of a userid
    $users = User::all();
    $count = 0;

    foreach($users as $user) {
        $friends = json_decode($user->friends, true);

        foreach($friends as $key => $friend) {
            if(in_array($friend['userid'], [5136, 5135, 5130, 5124, 5131])) {
                unset($friends[$key]);
                $user->friends = $friends;
                $user->save();
                $count++;
            }
        }
    }
    */

    /* delete unused avatar pfp
    $files = File::files('/var/www/cdn.finobe.net/avatar');
    $avatars = User::pluck('pfp')->toArray();

    foreach($avatars as $key => $avatar) {
        if(!str_contains($avatar, '/')) {
            unset($avatars[$key]);
            continue;
        }

        $avatars[$key] = str_replace('avatar/', '', $avatar);
    }

    foreach($files as $file) {
        sleep(0.2);
        if(!in_array($file->getFilename(), $avatars)) {
            $this->info('Deleting: ' . $file->getFilename());
            File::delete($file->getRealPath());
        }
    }
    */

    $assets = $db->table('assets')
        ->where('asset_type', 3)
        ->get()
        ->map(function ($item) {
            return (array) $item;
        })->toArray();
    
    foreach($assets as $asset) {
        $asset['additional'] = json_decode($asset['additional'], true);
        $asset['additional']['onSale'] = true;
        $asset['additional'] = json_encode($asset['additional']);

        $db->table('assets')
            ->where('id', $asset['id'])
            ->update([
                'additional' => $asset['additional']
            ]);
    }
    
    $this->info('Ran successfully');
});