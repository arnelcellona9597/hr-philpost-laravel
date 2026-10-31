@php
    use App\Enums\Role;
    use App\Enums\UserStatus;

    $me = auth()->user();
@endphp

<x-layouts.app title="HR Accounts" heading="HR accounts" description="Only System Admins can see this page. New accounts get an email invitation to choose their own password.">
    @if ($errors->any())
        <div class="mb-4 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0" />
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <section class="card mb-6 p-5">
        <h2 class="font-semibold">Add an HR account</h2>
        <form method="post" action="{{ route('users.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
            @csrf
            <div><label class="label" for="n-name">Full name</label><input class="input" id="n-name" name="name" required maxlength="120" value="{{ old('name') }}" /></div>
            <div><label class="label" for="n-email">Email</label><input class="input" id="n-email" name="email" type="email" required maxlength="254" value="{{ old('email') }}" /></div>
            <div>
                <label class="label" for="n-role">Role</label>
                <select class="input" id="n-role" name="role">
                    @foreach (Role::cases() as $role)
                        <option value="{{ $role->value }}" @selected($role === Role::HrStaff)>{{ $role->label() }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn-primary"><x-icon name="send" /> Create &amp; send invitation</button>
        </form>
    </section>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Name</th><th>Email</th><th>Role &amp; status</th><th>Access</th><th></th></tr></thead>
                <tbody>
                    @foreach ($users as $account)
                        @php($self = $account->is($me))
                        <tr>
                            <td class="font-medium">
                                <div class="flex items-center gap-3">
                                    <x-avatar :name="$account->name" :image-id="$account->avatar_id" class="size-8 text-xs" />
                                    <div class="min-w-0">
                                        {{ $account->name }}
                                        @if ($self)<span class="ml-2 badge-primary">You</span>@endif
                                        @unless ($account->email_verified_at)<span class="ml-2 badge-warning" title="Hasn't set a password / signed in yet">Invitation pending</span>@endunless
                                    </div>
                                </div>
                            </td>
                            <td class="text-fg-2">{{ $account->email }}</td>
                            <td>
                                <form method="post" action="{{ route('users.update', $account) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    @method('PUT')
                                    <select class="input w-auto py-1.5" name="role" aria-label="Role for {{ $account->name }}">
                                        @foreach (Role::cases() as $role)
                                            <option value="{{ $role->value }}" @selected($account->role === $role)>{{ $role->label() }}</option>
                                        @endforeach
                                    </select>
                                    <select class="input w-auto py-1.5" name="status" aria-label="Status for {{ $account->name }}">
                                        <option value="{{ UserStatus::Approved->value }}" @selected($account->status === UserStatus::Approved)>active</option>
                                        <option value="{{ UserStatus::Disabled->value }}" @selected($account->status !== UserStatus::Approved)>disabled</option>
                                    </select>
                                    <button class="btn-secondary btn-sm">Save</button>
                                    <span @class(['badge-success' => $account->isApproved(), 'badge-neutral' => ! $account->isApproved()])>{{ $account->isApproved() ? 'active' : $account->status->value }}</span>
                                </form>
                            </td>
                            <td>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($account->isLocked())
                                        <form method="post" action="{{ route('users.unlock', $account) }}">@csrf<button class="btn-secondary btn-sm">Unlock</button></form>
                                    @endif
                                    @unless ($account->email_verified_at)
                                        <form method="post" action="{{ route('users.link', $account) }}">@csrf<input type="hidden" name="kind" value="invite" /><button class="btn-ghost btn-sm">Resend invitation</button></form>
                                    @else
                                        @unless ($self)
                                            <form method="post" action="{{ route('users.link', $account) }}" data-confirm="Email {{ $account->name }} a link to choose a new password?">
                                                @csrf
                                                <input type="hidden" name="kind" value="reset" />
                                                <button class="btn-ghost btn-sm">Send password reset</button>
                                            </form>
                                        @endunless
                                    @endunless
                                </div>
                            </td>
                            <td class="text-right">
                                @unless ($self)
                                    <form method="post" action="{{ route('users.destroy', $account) }}" data-confirm="Delete the account of {{ $account->name }}? Disabling keeps their history linked; deleting removes the login.">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn-ghost btn-sm text-danger">Delete</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-4 grid gap-3 text-sm text-fg-2 md:grid-cols-2">
        <p class="card p-4"><strong class="text-fg">System Admin</strong> — everything HR Staff can do, plus adding, disabling and deleting HR accounts and the email server settings.</p>
        <p class="card p-4"><strong class="text-fg">HR Staff</strong> — every HR feature: records, deleting, imports, payroll, messages, settings and the audit log. Cannot manage HR accounts.</p>
    </div>
</x-layouts.app>
