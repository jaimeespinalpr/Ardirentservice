-- Ardi Rent & Service customer identity/profile migration.
-- Passwords and identity records remain exclusively in Supabase Auth.

create extension if not exists pgcrypto with schema extensions;

create table public.customer_profiles (
    id uuid primary key references auth.users(id) on delete cascade,
    full_name text not null,
    phone text null,
    marketing_opt_in boolean not null default false,
    marketing_opt_in_at timestamptz null,
    marketing_opt_out_at timestamptz null,
    welcome_discount_cents integer not null default 500,
    welcome_discount_used_at timestamptz null,
    welcome_discount_reservation_token uuid null,
    welcome_discount_reserved_at timestamptz null,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    constraint customer_profiles_discount_nonnegative check (welcome_discount_cents >= 0),
    constraint customer_profiles_name_length check (
        char_length(full_name) >= 1 and char_length(full_name) <= 120
    ),
    constraint customer_profiles_phone_length check (phone is null or char_length(phone) <= 40)
);

comment on table public.customer_profiles is
    'Customer profile and authoritative welcome-benefit state; identity is managed by auth.users.';

create or replace function public.handle_new_customer_user()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
    opted_in boolean := lower(coalesce(new.raw_user_meta_data ->> 'marketing_opt_in', 'false'))
        in ('true', '1', 'yes');
    supplied_name text := nullif(trim(coalesce(new.raw_user_meta_data ->> 'full_name', '')), '');
    supplied_phone text := nullif(trim(coalesce(new.raw_user_meta_data ->> 'phone', '')), '');
begin
    insert into public.customer_profiles (
        id, full_name, phone, marketing_opt_in, marketing_opt_in_at
    ) values (
        new.id,
        left(coalesce(supplied_name, 'Customer'), 120),
        left(supplied_phone, 40),
        opted_in,
        case when opted_in then now() else null end
    );
    return new;
end;
$$;

revoke all on function public.handle_new_customer_user() from public, anon, authenticated;

drop trigger if exists on_auth_user_created_customer_profile on auth.users;
create trigger on_auth_user_created_customer_profile
after insert on auth.users
for each row execute function public.handle_new_customer_user();

create or replace function public.set_customer_profile_update_fields()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
begin
    new.updated_at := now();
    if new.marketing_opt_in is distinct from old.marketing_opt_in then
        if new.marketing_opt_in then
            new.marketing_opt_in_at := now();
            new.marketing_opt_out_at := null;
        else
            new.marketing_opt_out_at := now();
        end if;
    end if;
    return new;
end;
$$;

revoke all on function public.set_customer_profile_update_fields() from public, anon, authenticated;

create trigger customer_profiles_set_update_fields
before update of full_name, phone, marketing_opt_in on public.customer_profiles
for each row execute function public.set_customer_profile_update_fields();

alter table public.customer_profiles enable row level security;

create policy customer_profiles_select_own
on public.customer_profiles
for select
to authenticated
using (auth.uid() = id);

create policy customer_profiles_update_own
on public.customer_profiles
for update
to authenticated
using (auth.uid() = id)
with check (auth.uid() = id);

revoke all on public.customer_profiles from public;
revoke all on public.customer_profiles from anon;
revoke all on public.customer_profiles from authenticated;
grant select on public.customer_profiles to authenticated;
revoke update on public.customer_profiles from authenticated;
grant update (full_name, phone, marketing_opt_in) on public.customer_profiles to authenticated;
grant all on public.customer_profiles to service_role;

-- Backend-only: locks one profile row, expires stale holds, and reserves once.
create or replace function public.reserve_welcome_discount(
    p_user_id uuid,
    p_ttl_seconds integer default 3600
)
returns uuid
language plpgsql
security definer
set search_path = ''
as $$
declare
    profile public.customer_profiles%rowtype;
    reservation_token uuid;
begin
    if p_ttl_seconds < 60 or p_ttl_seconds > 86400 then
        raise exception 'invalid reservation ttl';
    end if;

    select * into profile
    from public.customer_profiles
    where id = p_user_id
    for update;

    if not found or profile.welcome_discount_used_at is not null then
        return null;
    end if;

    if profile.welcome_discount_reservation_token is not null
       and profile.welcome_discount_reserved_at > now() - make_interval(secs => p_ttl_seconds) then
        return null;
    end if;

    reservation_token := extensions.gen_random_uuid();
    update public.customer_profiles
    set welcome_discount_reservation_token = reservation_token,
        welcome_discount_reserved_at = now(),
        updated_at = now()
    where id = p_user_id and welcome_discount_used_at is null;

    return reservation_token;
end;
$$;

-- Backend-only: releases only the matching unused reservation.
create or replace function public.release_welcome_discount(
    p_user_id uuid,
    p_reservation_token uuid
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
    affected integer;
begin
    update public.customer_profiles
    set welcome_discount_reservation_token = null,
        welcome_discount_reserved_at = null,
        updated_at = now()
    where id = p_user_id
      and welcome_discount_used_at is null
      and welcome_discount_reservation_token = p_reservation_token;
    get diagnostics affected = row_count;
    return affected = 1;
end;
$$;

-- Backend-only: consumes the matching reservation exactly once after paid verification.
create or replace function public.consume_welcome_discount(
    p_user_id uuid,
    p_reservation_token uuid
)
returns boolean
language plpgsql
security definer
set search_path = ''
as $$
declare
    affected integer;
begin
    update public.customer_profiles
    set welcome_discount_used_at = now(),
        welcome_discount_reservation_token = null,
        welcome_discount_reserved_at = null,
        updated_at = now()
    where id = p_user_id
      and welcome_discount_used_at is null
      and welcome_discount_reservation_token = p_reservation_token;
    get diagnostics affected = row_count;
    return affected = 1;
end;
$$;

-- Backend/maintenance-only cleanup for abandoned checkout holds.
create or replace function public.expire_abandoned_welcome_discounts(
    p_ttl_seconds integer default 3600
)
returns integer
language plpgsql
security definer
set search_path = ''
as $$
declare
    affected integer;
begin
    if p_ttl_seconds < 60 or p_ttl_seconds > 86400 then
        raise exception 'invalid reservation ttl';
    end if;
    update public.customer_profiles
    set welcome_discount_reservation_token = null,
        welcome_discount_reserved_at = null,
        updated_at = now()
    where welcome_discount_used_at is null
      and welcome_discount_reservation_token is not null
      and welcome_discount_reserved_at <= now() - make_interval(secs => p_ttl_seconds);
    get diagnostics affected = row_count;
    return affected;
end;
$$;

revoke all on function public.reserve_welcome_discount(uuid, integer) from public, anon, authenticated;
revoke all on function public.release_welcome_discount(uuid, uuid) from public, anon, authenticated;
revoke all on function public.consume_welcome_discount(uuid, uuid) from public, anon, authenticated;
revoke all on function public.expire_abandoned_welcome_discounts(integer) from public, anon, authenticated;

grant execute on function public.reserve_welcome_discount(uuid, integer) to service_role;
grant execute on function public.release_welcome_discount(uuid, uuid) to service_role;
grant execute on function public.consume_welcome_discount(uuid, uuid) to service_role;
grant execute on function public.expire_abandoned_welcome_discounts(integer) to service_role;
