<?php
protected function schedule(Schedule $schedule): void
{
    $schedule->job(new \App\Jobs\AutoReleaseDepositsJob())->hourly();
}
