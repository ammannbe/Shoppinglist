{{-- "Manage account" at the bottom of the settings (resources/js/account.js, AccountController). Part of the app shell:
     no user data here, everything comes from /me. All actions need a connection and are dimmed offline. --}}
<template x-if="loggedIn && $store.sync.user?.account">
    <div x-data="accountSettings" class="space-y-6">
        <div>
            <h2 class="mb-2 text-sm font-medium text-stone-600" x-text="$t('Manage account')"></h2>
            <div class="divide-y divide-stone-200 overflow-hidden rounded-lg border border-stone-200 bg-white">
                <button type="button" class="account-row" @click="logout()" :disabled="busy || !onlineNow">
                    <span class="min-w-0">
                        <span class="block" x-text="`🚪 ${$t('Log Out')}`"></span>
                        <span class="block break-all text-sm text-stone-500" x-text="$t('Logged in as :email', { email: $store.sync.user.email })"></span>
                    </span>
                </button>

                {{-- Linked to the OIDC login: unlinking asks for a new password (after a recent login at the provider) --}}
                <template x-if="$store.sync.user.linked && rows.provider">
                    <button type="button" class="account-row" @click="openAccountDialog('unlink')" :disabled="!rows.unlink || !onlineNow">
                        <span>
                            <span class="block" x-text="rows.unlink ? `🔓 ${$t('Unlink from :provider', { provider: rows.provider })}` : `🔗 ${$t('Connected with :provider', { provider: rows.provider })}`"></span>
                            <span class="block text-sm text-stone-500" x-text="rows.unlink ? $t('Connected with :provider', { provider: rows.provider }) : $t('Signing in with a password is turned off for this account.')"></span>
                        </span>
                        <span x-show="rows.unlink" aria-hidden="true">›</span>
                    </button>
                </template>
                <template x-if="rows.link">
                    <button type="button" class="account-row" @click="openAccountDialog('link')" :disabled="!onlineNow">
                        <span>
                            <span class="block" x-text="`🔗 ${$t('Link :provider', { provider: rows.provider })}`"></span>
                            <span class="block text-sm text-stone-500" x-text="$t('Sign in with :provider instead of a password', { provider: rows.provider })"></span>
                        </span>
                        <span aria-hidden="true">›</span>
                    </button>
                </template>

                {{-- Linked accounts: profile in the AccountManager (new tab) --}}
                <template x-if="rows.manager">
                    <a :href="rows.manager.url" target="_blank" rel="noopener" data-online class="account-row">
                        <span>
                            <span class="block" x-text="`👤 ${$t('Manage account in “:name”', { name: rows.manager.name })}`"></span>
                            <span class="block text-sm text-stone-500" x-text="$t('Name, e-mail address and password')"></span>
                        </span>
                        <span aria-hidden="true">↗</span>
                    </a>
                </template>

                {{-- Local accounts: e-mail and password (Fortify) --}}
                <template x-if="rows.credentials">
                    <details class="group/details">
                        <summary class="account-row cursor-pointer list-none">
                            <span>
                                <span class="block" x-text="`✉️ ${$t('Change e-mail and password')}`"></span>
                                <span class="block text-sm text-stone-500" x-text="$t('Sign-in with your e-mail address')"></span>
                            </span>
                            <span class="transition-transform group-open/details:rotate-90" aria-hidden="true">›</span>
                        </summary>
                        <div class="space-y-6 px-4 pb-4">
                            <p x-show="!onlineNow" class="notice" x-text="$t('This needs an internet connection.')"></p>
                            <p x-show="!rows.hasPassword" class="text-sm text-stone-600" x-text="$t('Your account has no password yet. You can set one with &quot;Forgot your password?&quot; on the login page.')"></p>
                            <template x-if="rows.hasPassword">
                                <div class="space-y-6">
                                    <form @submit.prevent="changeEmail()" class="space-y-3">
                                        <h3 class="font-medium" x-text="$t('Change e-mail')"></h3>
                                        <p class="text-sm text-stone-600" x-text="$t('You confirm the new address with a link we send there.')"></p>
                                        <label class="block">
                                            <span class="label" x-text="$t('New e-mail address')"></span>
                                            <input type="email" x-model="emailForm.email" class="field w-full" required maxlength="255" autocomplete="email">
                                        </label>
                                        <label class="block">
                                            <span class="label" x-text="$t('Current Password')"></span>
                                            <input type="password" x-model="emailForm.current_password" class="field w-full" required autocomplete="current-password">
                                        </label>
                                        <p x-show="emailError" class="text-sm text-red-700" role="alert" x-text="emailError"></p>
                                        <button class="button" :disabled="accountBusy || !onlineNow" x-text="$t('Change e-mail')"></button>
                                    </form>
                                    <form @submit.prevent="changePassword()" class="space-y-3">
                                        <h3 class="font-medium" x-text="$t('Change password')"></h3>
                                        <input type="text" :value="$store.sync.user.email" autocomplete="username" hidden>
                                        <label class="block">
                                            <span class="label" x-text="$t('Current Password')"></span>
                                            <input type="password" x-model="passwordForm.current_password" class="field w-full" required autocomplete="current-password">
                                        </label>
                                        <label class="block">
                                            <span class="label" x-text="$t('New Password')"></span>
                                            <input type="password" x-model="passwordForm.password" class="field w-full" required autocomplete="new-password">
                                        </label>
                                        <label class="block">
                                            <span class="label" x-text="$t('Confirm Password')"></span>
                                            <input type="password" x-model="passwordForm.password_confirmation" class="field w-full" required autocomplete="new-password">
                                        </label>
                                        <p x-show="passwordError" class="text-sm text-red-700" role="alert" x-text="passwordError"></p>
                                        <button class="button" :disabled="accountBusy || !onlineNow" x-text="$t('Change password')"></button>
                                    </form>
                                </div>
                            </template>
                        </div>
                    </details>
                </template>

                {{-- Local accounts: passkeys (adding and removing need a password confirmation) --}}
                <template x-if="rows.passkeys">
                    <details class="group/details" @toggle="$event.target.open && loadPasskeys()">
                        <summary class="account-row cursor-pointer list-none">
                            <span>
                                <span class="block" x-text="`🔑 ${$t('Passkeys')}`"></span>
                                <span class="block text-sm text-stone-500" x-text="$store.sync.user.account.passkeys
                                    ? ($store.sync.user.account.passkeys === 1 ? $t('1 passkey') : $t(':count passkeys', { count: $store.sync.user.account.passkeys }))
                                    : $t('Face ID, Touch ID, Windows Hello or the device PIN')"></span>
                            </span>
                            <span class="transition-transform group-open/details:rotate-90" aria-hidden="true">›</span>
                        </summary>
                        <div class="space-y-4 px-4 pb-4">
                            <p class="text-sm text-stone-600" x-text="$t('Log in with Face ID, Touch ID, Windows Hello or your device PIN instead of a password.')"></p>
                            <p x-show="!onlineNow" class="notice" x-text="$t('This needs an internet connection.')"></p>
                            <ul x-show="passkeyList?.length" class="divide-y divide-stone-200 rounded-lg border border-stone-200">
                                <template x-for="passkey in passkeyList ?? []" :key="passkey.id">
                                    <li class="flex items-center gap-3 px-3 py-2">
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium" x-text="passkey.name"></span>
                                            <span class="block text-xs text-stone-500" x-text="passkey.used"></span>
                                        </span>
                                        <button type="button" class="text-sm text-red-700 underline disabled:opacity-50" @click="removePasskey(passkey)" :disabled="accountBusy || !onlineNow" x-text="$t('Remove')"></button>
                                    </li>
                                </template>
                            </ul>

                            {{-- Password confirmation before adding or removing (Fortify's password.confirm) --}}
                            <template x-if="passkeyConfirming">
                                <form @submit.prevent="confirmPassword()" class="space-y-3 rounded-lg bg-stone-50 p-3">
                                    <p class="text-sm" x-text="$t('Please confirm your password before continuing.')"></p>
                                    <input type="text" :value="$store.sync.user.email" autocomplete="username" hidden>
                                    <input type="password" x-model="passkeyPassword" class="field w-full" required autocomplete="current-password" :aria-label="$t('Password')" :placeholder="$t('Password')">
                                    <div class="flex flex-wrap gap-2">
                                        <button class="button" :disabled="accountBusy || !onlineNow" x-text="$t('Confirm')"></button>
                                        <button type="button" x-show="passkeysSupported && $store.sync.user.account.passkeys" class="button-secondary" @click="confirmWithPasskey()" :disabled="!onlineNow" x-text="$t('Confirm with a passkey')"></button>
                                        <button type="button" class="button-secondary" @click="cancelConfirmation()" x-text="$t('Cancel')"></button>
                                    </div>
                                </form>
                            </template>

                            <p x-show="!passkeysSupported" class="text-sm text-stone-600" x-text="$t('This browser does not support passkeys.')"></p>
                            <p x-show="passkeysSupported && !rows.hasPassword" class="text-sm text-stone-600" x-text="$t('Your account has no password yet. You can set one with &quot;Forgot your password?&quot; on the login page.')"></p>
                            <form x-show="passkeysSupported && rows.hasPassword && !passkeyConfirming" @submit.prevent="addPasskey()" class="flex flex-wrap gap-2">
                                <input x-model="passkeyName" class="field min-w-0 flex-1" maxlength="255" :placeholder="$t('Name (optional)')" :aria-label="$t('Name (optional)')">
                                <button class="button" :disabled="accountBusy || !onlineNow" x-text="$t('Add passkey')"></button>
                            </form>
                            <p x-show="passkeyError" class="text-sm text-red-700" role="alert" x-text="passkeyError"></p>
                        </div>
                    </details>
                </template>
            </div>
        </div>

        {{-- Last: deleting the account --}}
        <div class="overflow-hidden rounded-lg border border-stone-200 bg-white">
            <button type="button" class="account-row text-red-700" @click="openAccountDialog('delete')" :disabled="!onlineNow">
                <span>
                    <span class="block font-medium" x-text="`🗑️ ${$t('Delete account')}`"></span>
                    <span class="block text-sm text-stone-500" x-text="$t('Deletes your account and your data for good')"></span>
                </span>
                <span aria-hidden="true">›</span>
            </button>
        </div>

        <dialog x-ref="accountDialog" class="dialog" @close="accountDialog = null">
            {{-- Unlink: a new password (the recent login at the provider is checked by the server) --}}
            <form x-show="accountDialog === 'unlink'" @submit.prevent="unlink()" class="space-y-4">
                <h2 class="text-lg font-semibold" x-text="$t('Unlink from :provider', { provider: rows?.provider })"></h2>
                <p class="text-sm text-stone-600" x-text="$t('Choose a password. You then sign in with your e-mail address and this password instead of :provider.', { provider: rows?.provider })"></p>
                <input type="text" :value="$store.sync.user?.email" autocomplete="username" hidden>
                <label class="block">
                    <span class="label" x-text="$t('New Password')"></span>
                    <input type="password" x-model="accountForm.password" class="field w-full" :required="accountDialog === 'unlink'" autocomplete="new-password">
                </label>
                <label class="block">
                    <span class="label" x-text="$t('Confirm Password')"></span>
                    <input type="password" x-model="accountForm.password_confirmation" class="field w-full" :required="accountDialog === 'unlink'" autocomplete="new-password">
                </label>
                <p x-show="accountError" class="text-sm text-red-700" role="alert" x-text="accountError"></p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="button-secondary" @click="closeAccountDialog()" x-text="$t('Cancel')"></button>
                    <button class="button" :disabled="accountBusy || !onlineNow" x-text="$t('Unlink from :provider', { provider: rows?.provider })"></button>
                </div>
            </form>

            {{-- Link: the current password first (accounts without one skip it), then the provider --}}
            <form x-show="accountDialog === 'link'" @submit.prevent="link()" class="space-y-4">
                <h2 class="text-lg font-semibold" x-text="$t('Link :provider', { provider: rows?.provider })"></h2>
                <p class="text-sm text-stone-600">
                    <span x-text="$t('Link your account to log in with :provider.', { provider: rows?.provider })"></span>
                    <span x-text="$t('You then sign in with :provider only: your password and passkeys are removed and your other devices are signed out.', { provider: rows?.provider })"></span>
                </p>
                <template x-if="rows?.hasPassword">
                    <label class="block">
                        <span class="label" x-text="$t('Current Password')"></span>
                        <input type="text" :value="$store.sync.user?.email" autocomplete="username" hidden>
                        <input type="password" x-model="accountForm.password" class="field w-full" :required="accountDialog === 'link'" autocomplete="current-password">
                    </label>
                </template>
                <p x-show="accountError" class="text-sm text-red-700" role="alert" x-text="accountError"></p>
                <p x-show="$store.sync.user?.account?.oidc?.signup_url" class="text-sm text-stone-600">
                    <span x-text="$t('No :provider account yet?', { provider: rows?.provider })"></span>
                    <a class="underline" :href="$store.sync.user?.account?.oidc?.signup_url" target="_blank" rel="noopener" x-text="$t('Create one')"></a>
                </p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="button-secondary" @click="closeAccountDialog()" x-text="$t('Cancel')"></button>
                    <button class="button" :disabled="accountBusy || !onlineNow" x-text="$t('Link :provider', { provider: rows?.provider })"></button>
                </div>
            </form>

            {{-- Delete: the password, or (linked accounts) a recent login at the provider --}}
            <form x-show="accountDialog === 'delete'" @submit.prevent="deleteAccount()" class="space-y-4">
                <h2 class="text-lg font-semibold text-red-800" x-text="$t('Delete account')"></h2>
                <p class="text-sm text-stone-600" x-text="$t('Your lists without other members are deleted, shared lists go to another member. Your own products, shops and planned recipes are deleted too.')"></p>
                <template x-if="rows?.deletion === 'password'">
                    <label class="block">
                        <span class="label" x-text="$t('Current Password')"></span>
                        <input type="text" :value="$store.sync.user?.email" autocomplete="username" hidden>
                        <input type="password" x-model="accountForm.password" class="field w-full" :required="accountDialog === 'delete'" autocomplete="current-password">
                    </label>
                </template>
                <p x-show="rows?.deletion === 'provider'" class="text-sm text-stone-600" x-text="$t('For your security, confirm with :provider first.', { provider: rows?.provider })"></p>
                <p x-show="rows?.deletion === 'unavailable'" class="text-sm text-stone-600" x-text="$t('Deleting the account needs a confirmation with :provider, which is turned off at the moment.', { provider: rows?.provider })"></p>
                <p x-show="rows?.deletion === 'no-password'" class="text-sm text-stone-600" x-text="$t('To delete your account, first set a password with &quot;Forgot your password?&quot; on the login page.')"></p>
                <p x-show="accountError" class="text-sm text-red-700" role="alert" x-text="accountError"></p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="button-secondary" @click="closeAccountDialog()" x-text="$t('Cancel')"></button>
                    <button type="button" x-show="rows?.deletion === 'provider'" class="button" @click="confirmAtProvider()" :disabled="!onlineNow" x-text="$t('Continue with :provider', { provider: rows?.provider })"></button>
                    <button x-show="['password', 'confirmed'].includes(rows?.deletion)" class="button-danger" :disabled="accountBusy || !onlineNow" x-text="$t('Delete account for good')"></button>
                </div>
            </form>
        </dialog>
    </div>
</template>
