<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('kraft:maintenance')->hourly()->withoutOverlapping(60);
Schedule::command('kraft:remote-feeds-sync')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('kraft:content-publish')->everyMinute()->withoutOverlapping(5);
Schedule::command('notifications:deliver')->everyMinute()->withoutOverlapping(5);
Schedule::command('audit:webhooks')->everyMinute()->withoutOverlapping(10);
Schedule::command('content:announcements')->everyMinute()->withoutOverlapping(10);
Schedule::command('servers:poll')->everyMinute()->withoutOverlapping(5);
Schedule::command('discord:roles-sync')->everyMinute()->withoutOverlapping(10);
Schedule::command('kraft:tasks-run')->everyMinute();
