#!/bin/sh
# Runs on the VPS: makes (or resets) the super admin account superadmin@exams.local.
# Only the password's bcrypt hash is here (the repository is public); the password was handed over privately.
set -e
sudo docker exec -e NEW_HASH='$2y$12$4TbMJBMadEE60A.8.4Lxt.YAC5OUofXR9s.UpjczMwYi3r52IHptO' examnest-app php artisan tinker --execute='
$u = App\Models\User::firstOrNew(["email" => "superadmin@exams.local"]);
$u->forceFill(["name" => "المدير العام", "password" => getenv("NEW_HASH"), "role" => "admin", "is_master_admin" => true, "is_active" => true,
  "is_examiner" => true, "is_invigilator" => true, "can_manage_appearance" => true, "can_manage_users" => true, "can_manage_translations" => true,
  "can_view_statistics" => true, "can_manage_settings" => true, "email_verified_at" => now(), "two_factor_secret" => null, "two_factor_recovery_codes" => null])->save();
echo "super admin ready, id ".$u->id.PHP_EOL;'
