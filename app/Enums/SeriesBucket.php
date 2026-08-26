<?php

namespace App\Enums;

/**
 * The two bucket sizes a trend series can be densified to (PRD-11 Amendment
 * B(i); plan-11 Technical ruling 11). Not persisted anywhere — the window is
 * the only input to this choice, and it is made in exactly one place,
 * `AnalyticsWindow::bucket()`. Nothing outside that method may construct one
 * from a window value.
 */
enum SeriesBucket: string
{
    case Hour = 'hour';
    case Day = 'day';
}
