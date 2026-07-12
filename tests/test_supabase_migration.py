from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
MIGRATIONS = ROOT / "supabase" / "migrations"


def migration_sql() -> str:
    files = sorted(MIGRATIONS.glob("*_customer_profiles_and_welcome_discount.sql"))
    assert len(files) == 1, "expected one versioned customer profile migration"
    return files[0].read_text().lower()


def test_customer_profiles_schema_and_constraints():
    sql = migration_sql()
    required = [
        "create table public.customer_profiles",
        "id uuid primary key references auth.users(id) on delete cascade",
        "full_name text not null",
        "phone text null",
        "marketing_opt_in boolean not null default false",
        "marketing_opt_in_at timestamptz null",
        "marketing_opt_out_at timestamptz null",
        "welcome_discount_cents integer not null default 500",
        "welcome_discount_used_at timestamptz null",
        "welcome_discount_reservation_token uuid null",
        "welcome_discount_reserved_at timestamptz null",
        "created_at timestamptz not null default now()",
        "updated_at timestamptz not null default now()",
        "check (welcome_discount_cents >= 0)",
        "char_length(full_name) <= 120",
        "char_length(phone) <= 40",
    ]
    for fragment in required:
        assert fragment in sql
    assert " email " not in re.sub(r"--.*", "", sql)


def test_trigger_creates_profiles_from_auth_users_safely():
    sql = migration_sql()
    assert "create or replace function public.handle_new_customer_user()" in sql
    assert "new.raw_user_meta_data ->> 'full_name'" in sql
    assert "new.raw_user_meta_data ->> 'phone'" in sql
    assert "new.raw_user_meta_data ->> 'marketing_opt_in'" in sql
    assert "after insert on auth.users" in sql
    assert "set search_path = ''" in sql


def test_rls_and_column_level_permissions_are_restrictive():
    sql = migration_sql()
    assert "enable row level security" in sql
    assert "auth.uid() = id" in sql
    assert "grant select on public.customer_profiles to authenticated" in sql
    assert "grant update (full_name, phone, marketing_opt_in)" in sql
    assert "revoke all on public.customer_profiles from anon" in sql
    assert "revoke update on public.customer_profiles from authenticated" in sql
    assert "using (true)" not in sql
    assert "with check (true)" not in sql


def test_atomic_functions_have_safe_definers_and_restricted_grants():
    sql = migration_sql()
    for name in (
        "reserve_welcome_discount",
        "release_welcome_discount",
        "consume_welcome_discount",
        "expire_abandoned_welcome_discounts",
    ):
        assert f"function public.{name}" in sql
    assert sql.count("security definer") >= 4
    assert sql.count("set search_path = ''") >= 5
    assert "gen_random_uuid()" in sql
    assert "for update" in sql
    assert "welcome_discount_used_at is null" in sql
    assert "revoke all on function public.consume_welcome_discount" in sql
    assert "grant execute on function public.consume_welcome_discount" in sql
    assert "to service_role" in sql
    assert "to anon" not in re.sub(r"revoke[^;]+;", "", sql)
