<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * Access level of an account. A user is always a member of the church; the role is the
 * office that member holds in the system.
 *
 * Stored values are Spanish and gender-specific (`secretario`, `tesorero`, `contralor`),
 * unlike the old system's (`secretaria`, `tesoreria`, `contraloria`), see {@see fromLegacy()}.
 */
enum UserRole: string
{
    /** Super administrator: holds every permission. */
    case Pastor = 'pastor';
    case Assistant = 'asistente';
    case Secretary = 'secretario';
    case Treasurer = 'tesorero';
    case Comptroller = 'contralor';
    /** Portal-only account of a regular member: no module access, only their own profile. */
    case Member = 'miembro';

    /**
     * The permissions this role holds. The only place where roles are mapped to abilities.
     *
     * Secretary = people, Treasurer = money, Comptroller = read-only oversight plus audits
     * (Treasury is kept out of audits on purpose: the audited does not watch the audit).
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Pastor => Permission::cases(),
            self::Assistant => [
                Permission::DashboardView,
                Permission::MembersView,
            ],
            self::Secretary => [
                Permission::DashboardView,
                Permission::MembersView,
                Permission::MembersManage,
                Permission::MemberReportsView,
                Permission::ChurchSettingsManage,
                Permission::AssetsView,
                Permission::AssetsManage,
            ],
            self::Treasurer => [
                Permission::DashboardView,
                Permission::OfferingsView,
                Permission::OfferingsManage,
                Permission::ClosuresView,
                Permission::ClosuresManage,
                Permission::AccountingView,
                Permission::AccountingManage,
                Permission::CatalogsView,
            ],
            self::Comptroller => [
                Permission::DashboardView,
                Permission::MembersView,
                Permission::MemberReportsView,
                Permission::OfferingsView,
                Permission::ClosuresView,
                Permission::AccountingView,
                Permission::CatalogsView,
                Permission::SealedLedgerView,
                Permission::AuditsView,
                Permission::AuditsRecord,
            ],
            self::Member => [],
        };
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Name shown to people (translated).
     */
    public function label(): string
    {
        return match ($this) {
            self::Pastor => __('Pastor'),
            self::Assistant => __('Assistant'),
            self::Secretary => __('Secretary'),
            self::Treasurer => __('Treasurer'),
            self::Comptroller => __('Comptroller'),
            self::Member => __('Member'),
        };
    }

    /**
     * Maps a role value of the old system (`secretaria`, `tesoreria`, `contraloria`...).
     *
     * @throws InvalidArgumentException when the value is not a known legacy role
     */
    public static function fromLegacy(string $value): self
    {
        return match ($value) {
            'pastor' => self::Pastor,
            'secretaria' => self::Secretary,
            'tesoreria' => self::Treasurer,
            'contraloria' => self::Comptroller,
            'miembro' => self::Member,
            default => throw new InvalidArgumentException("Unknown legacy role [{$value}]."),
        };
    }
}
