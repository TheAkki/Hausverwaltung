<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\RentPayment;
use Carbon\Carbon;

/**
 * Bucht eingegangene Mietzahlungen anhand ihres Zahlungsdatums: Welcher Monat mit
 * einer Zahlung bezahlt wurde, entscheidet die Halbmonatsregel (periodFor()).
 * Gemeinsame Logik für Seeder und REST-API.
 *
 * Ein gekoppelter Mietvertrag bucht keine eigenen Zahlungen (Konzept.md "###
 * Mietvertrag": "Eine Buchung ist dann für diesen Mietvertrag nicht nötig, sondern
 * wird mit der Buchung im referenzierten Mietvertrag verbucht").
 */
final class RentPaymentBooker
{
    /**
     * Höchstens so viele Tage vor dem erwarteten Zahltag des Folgemonats gilt eine
     * Zahlung bereits als Miete für den Folgemonat.
     */
    public const EARLY_PAYMENT_DAYS = 15;

    public function __construct(private readonly RentPaymentGenerator $generator) {}

    /**
     * Bucht je Datum die erste noch offene Mietzahlung des zugehörigen Monats. Ist die
     * Zahlung dieses Monats schon mit genau diesem Datum gebucht, wird sie unverändert
     * zurückgegeben (erneutes Buchen ändert nichts). Ein Datum ohne passendes
     * Mietintervall oder ohne offene Zahlung bleibt unberücksichtigt (null).
     *
     * @param  list<string>  $dates
     * @return array<string, RentPayment|null> je Datum die gebuchte Zahlung
     *
     * @throws \InvalidArgumentException bei einem gekoppelten Mietvertrag
     */
    public function bookAll(Lease $lease, array $dates): array
    {
        if ($lease->coupled_to_lease_id !== null) {
            throw new \InvalidArgumentException(
                'Der Mietvertrag ist an einen anderen Mietvertrag gekoppelt; Zahlungen werden beim referenzierten Mietvertrag gebucht.'
            );
        }

        foreach ($lease->leaseIntervals as $interval) {
            $this->generator->generate($interval);
        }

        $booked = [];

        foreach ($dates as $date) {
            $booked[$date] = $this->bookOne($lease, $date);
        }

        return $booked;
    }

    /**
     * Monat, für den eine am $date eingegangene Zahlung gilt: Liegt sie nach dem
     * erwarteten Zahltag dieses Monats und höchstens EARLY_PAYMENT_DAYS vor (oder nach)
     * dem des Folgemonats, ist es die Miete des Folgemonats, sonst die des eigenen.
     * Der erwartete Zahltag ist der Tag des Mietbeginns (meist der 1.).
     */
    public function periodFor(string $date, int $expectedDayOfMonth = 1): Carbon
    {
        $parsed = Carbon::parse($date);
        $thisMonthExpected = $this->clampedDay($parsed->copy()->startOfMonth(), $expectedDayOfMonth);
        $nextMonthExpected = $this->clampedDay($parsed->copy()->startOfMonth()->addMonthNoOverflow(), $expectedDayOfMonth);

        // abs(): diffInDays() ist vorzeichenbehaftet (Carbon 3).
        return $parsed->gt($thisMonthExpected) && abs($parsed->diffInDays($nextMonthExpected)) <= self::EARLY_PAYMENT_DAYS
            ? $nextMonthExpected->copy()->startOfMonth()
            : $parsed->copy()->startOfMonth();
    }

    private function bookOne(Lease $lease, string $date): ?RentPayment
    {
        $monthStart = $this->periodFor($date, $lease->start_date->day);
        $interval = $lease->leaseIntervals->first(
            fn ($leaseInterval) => $leaseInterval->start_date->lte($monthStart->copy()->endOfMonth())
                && ($leaseInterval->end_date === null || $leaseInterval->end_date->gte($monthStart))
        );

        if ($interval === null) {
            return null;
        }

        $payments = RentPayment::where('lease_interval_id', $interval->id)->whereDate('period_date', $monthStart);
        $alreadyBooked = (clone $payments)->whereDate('booking_date', $date)->first();

        if ($alreadyBooked !== null) {
            return $alreadyBooked;
        }

        $payment = $payments->whereNull('booking_date')->first();
        $payment?->update(['booking_date' => $date]);

        return $payment;
    }

    private function clampedDay(Carbon $monthStart, int $day): Carbon
    {
        return $monthStart->copy()->day(min($day, $monthStart->daysInMonth));
    }
}
