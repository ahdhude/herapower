<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\View;
use App\Services\Progress;

final class TodayController
{
    public function index(Request $req): string
    {
        $now = now();
        $review = Auth::can('approve') ? Progress::forDay($now->modify('-1 day'), $now) : null;
        return View::render('today', [
            'title'    => 'Today',
            'active'   => 'today',
            'progress' => Progress::forDay($now, $now),
            'review'   => $review,
        ]);
    }

    public function more(Request $req): string
    {
        return View::render('more', ['title' => 'More', 'active' => 'more']);
    }
}
