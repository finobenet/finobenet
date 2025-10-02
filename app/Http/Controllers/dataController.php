<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class dataController extends Controller
{
    public function time_elapsed_string($datetime) {
        $now = new \DateTime();
        $ago = new \DateTime($datetime);
        $diff = $now->diff($ago);

        $years = $diff->y;
        $months = $diff->m;
        $days = $diff->d;
        $hours = $diff->h;
        $minutes = $diff->i;
        $seconds = $diff->s;

        if ($years > 0) {
                return $years . " year" . ($years > 1 ? "s" : "") . " ago";
        } elseif ($months > 0) {
                return $months . " month" . ($months > 1 ? "s" : "") . " ago";
        } elseif ($days > 0) {
                return $days . " day" . ($days > 1 ? "s" : "") . " ago";
        } elseif ($hours > 0) {
                return $hours . " hour" . ($hours > 1 ? "s" : "") . " ago";
        } elseif ($minutes > 0) {
                return $minutes . " minute" . ($minutes > 1 ? "s" : "") . " ago";
        } else {
                return "just now";
        }
    }
    
    public function formatNumber($number) {
        $suffix = '';
        $negative = $number < 0;
        $number = abs($number);

        if ($number >= 1e39) {
            $suffix = 'NN'; // Nonillion
            $number = round($number / 1e39, 1);
        } elseif ($number >= 1e35) {
            $suffix = 'O'; // Octillion
            $number = round($number / 1e35, 1);
        } elseif ($number >= 1e31) {
            $suffix = 'Sp'; // Septillion
            $number = round($number / 1e31, 1);
        } elseif ($number >= 1e27) {
            $suffix = 'S'; // Sextillion
            $number = round($number / 1e27, 1);
        } elseif ($number >= 1e23) {
            $suffix = 'QQ'; // Quintillion
            $number = round($number / 1e23, 1);
        } elseif ($number >= 1e19) {
            $suffix = 'Q'; // Quadrillion
            $number = round($number / 1e19, 1);
        } elseif ($number >= 1e15) {
            $suffix = 'T'; // Trillion
            $number = round($number / 1e15, 1);
        } elseif ($number >= 1e11) {
            $suffix = 'B'; // Billion
            $number = round($number / 1e11, 1);
        } elseif ($number >= 1e7) {
            $suffix = 'M'; // Million
            $number = round($number / 1e7, 1);
        } elseif ($number >= 1e3) {
            $suffix = 'K'; // Thousand
            $number = round($number / 1e3, 1);
        }

        return ($negative ? '-' : '') . $number . $suffix;
    }

    public function timestamp(float $seconds) {
        $seconds = round($seconds);
        if ($seconds > 60 * 60 * 24) {
            // over a day
            $days = floor($seconds / (60 * 60 * 24));
            $hours = floor(fmod(($seconds % (60 * 60 * 24)), (60 * 60)) / (60 * 60));
            $minutes = (int) round(fmod(($seconds % (60 * 60)), 60) / 60);
            $seconds = fmod($seconds, 60);
        
            return sprintf("%d:%02d:%02d:%02d", $days, $hours, $minutes, $seconds);
        } elseif ($seconds > 60 * 60) {
            // over an hour
            $hours = floor($seconds / (60 * 60));
            $minutes = (int) round(fmod(($seconds % (60 * 60)), 60) / 60);
            $seconds = fmod($seconds, 60);
        
            return sprintf("%d:%02d:%02d", $hours, $minutes, $seconds);
        } else {
            // less than an hour
            $minutes = (int) round($seconds / 60);
            $seconds = fmod($seconds, 60);
        
            return sprintf("%d:%02d", $minutes, $seconds);
        }
    }

    public function remove_emoji($text) {
        $clean_text = "";
        $regexEmoticons = '/[\x{1F600}-\x{1F64F}]/u';
        $clean_text = preg_replace($regexEmoticons, '', $text);
        $regexSymbols = '/[\x{1F300}-\x{1F5FF}]/u';
        $clean_text = preg_replace($regexSymbols, '', $clean_text);
        $regexTransport = '/[\x{1F680}-\x{1F6FF}]/u';
        $clean_text = preg_replace($regexTransport, '', $clean_text);
        $regexMisc = '/[\x{2600}-\x{26FF}]/u';
        $clean_text = preg_replace($regexMisc, '', $clean_text);
        $regexDingbats = '/[\x{2700}-\x{27BF}]/u';
        $clean_text = preg_replace($regexDingbats, '', $clean_text);
        return $clean_text;
    }

    public function send_discord_message($message, $username = 'Aesthetiful Bot', $avatar = false) {
		$json_data = [
            "content" => $message,
            "username" => str_replace("@", "", $username),
            "avatar_url" => ($avatar ? $avatar : env('APP_URL', 'https://cdn.eracast.cc') . '/s/img/logo.png'),
            "tts" => false,
            "embeds" => []
        ];

		$response = Http::withHeaders([
			'Content-Type' => 'application/json',
		])->post(env('DISCORD_WEBHOOK', 'https://discord.com/api/webhooks/' . env('FINOBE_DISCORD_WEBHOOK')), $json_data);

        return;
	}

    public static function arbiter_pool() {
        // returns random arbiter instance
        $arbiters = [
            //'45.131.65.123',
            '74.208.123.25',
            //'104.223.8.156'
        ];

        return $arbiters[random_int(0, count($arbiters) - 1)];
    }
}
