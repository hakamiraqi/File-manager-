#!/bin/sh
# Rotates the FileNest admin password whose first value was shown in a public run summary.
# Only changes it if the account still uses that first password; also signs it out everywhere.
set -eu
file="$HOME/filenest-admin-password.txt"
[ -f "$file" ] || { echo "RESULT: no password file on the server"; exit 0; }
old="$(sed -n 's/^password: //p' "$file")"
out="$(sudo docker exec -e OLD="$old" -e NEW_HASH="$NEW_HASH" filenest-app php artisan tinker --execute='
$u = App\Models\User::where("email", "admin@filenest.test")->first();
if (! $u) { echo "NO_USER"; return; }
if (getenv("OLD") === "" || ! Illuminate\Support\Facades\Hash::check(getenv("OLD"), $u->password)) { echo "ALREADY_CHANGED"; return; }
Illuminate\Support\Facades\DB::table("users")->where("id", $u->id)->update(["password" => getenv("NEW_HASH"), "remember_token" => null]);
if (Illuminate\Support\Facades\Schema::hasTable("sessions")) { Illuminate\Support\Facades\DB::table("sessions")->where("user_id", $u->id)->delete(); }
echo "ROTATED";
' 2>&1 | tail -1)"
echo "RESULT: $out"
if [ "$out" = "ROTATED" ]; then
    # Keep the file (deploy.sh resets the password when it is missing) but without any password in it.
    (umask 077; printf 'email: admin@filenest.test\npassword: (changed on %s; handed to the owner privately)\n' "$(date +%F)" > "$file")
fi
