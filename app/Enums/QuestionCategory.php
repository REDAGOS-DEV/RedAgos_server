<?php

namespace App\Enums;

/**
 * How the donor app groups the questionnaire for display.
 *
 * The DOH form groups questions by time window ("in the past 12 months",
 * "have you ever"), which puts sexual history next to surgery and travel next
 * to HIV. On paper a nurse walks the donor through it; on a phone the donor
 * reads it alone, and a topic at a time is easier to answer honestly.
 *
 * Display only. The DOH sections, numbering and order are untouched, and they
 * are still what the staff view and the screening record use. Case order is
 * the order the donor sees, from the everyday to the most sensitive.
 */
enum QuestionCategory: string
{
    case HealthToday = 'health_today';

    case WomensHealth = 'womens_health';

    case DonationsAndProcedures = 'donations_procedures';

    case TravelAndExposure = 'travel_exposure';

    case SexualHistory = 'sexual_history';

    case Infections = 'infections';

    case MedicalHistory = 'medical_history';

    case BeforeYouDonate = 'before_you_donate';

    public function title(): string
    {
        return match ($this) {
            self::HealthToday => 'Your health today',
            self::WomensHealth => "Women's health",
            self::DonationsAndProcedures => 'Donations and procedures',
            self::TravelAndExposure => 'Travel and exposure',
            self::SexualHistory => 'Sexual history',
            self::Infections => 'Infections and risk',
            self::MedicalHistory => 'Medical history',
            self::BeforeYouDonate => 'Before you donate',
        };
    }
}
