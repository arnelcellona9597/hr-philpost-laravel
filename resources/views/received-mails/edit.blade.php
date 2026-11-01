<x-layouts.app title="Edit mail" heading="Edit mail" :back="['href' => route('received-mails.index', ['month' => $mail->received_on->format('Y-m')]), 'label' => 'Received mails']" :description="$mail->tracking_number">
    @if ($errors->has('form'))
        <div class="mb-4 rounded-lg bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">{{ $errors->first('form') }}</div>
    @endif

    <form method="post" action="{{ route('received-mails.update', $mail) }}" class="card grid max-w-4xl gap-4 p-5 sm:grid-cols-2" data-dirty-guard>
        @csrf
        @method('PUT')
        <div><label class="label" for="received_on">Date received</label><input class="input" id="received_on" name="received_on" type="date" required value="{{ old('received_on', $mail->received_on->toDateString()) }}" /></div>
        <div><label class="label" for="received_by">Received by</label><input class="input" id="received_by" name="received_by" maxlength="120" value="{{ old('received_by', $mail->received_by) }}" /></div>
        <div><label class="label" for="tracking_number">Tracking number</label><input class="input font-mono" id="tracking_number" name="tracking_number" required maxlength="60" value="{{ old('tracking_number', $mail->tracking_number) }}" /></div>
        <div><label class="label" for="post_office">Post office</label><input class="input" id="post_office" name="post_office" required maxlength="120" list="offices" value="{{ old('post_office', $mail->post_office) }}" /></div>
        <div class="sm:col-span-2"><label class="label" for="description">Description</label><textarea class="input min-h-24" id="description" name="description" required maxlength="2000">{{ old('description', $mail->description) }}</textarea></div>
        <div class="sm:col-span-2"><label class="label" for="remarks">Remarks</label><input class="input" id="remarks" name="remarks" maxlength="1000" value="{{ old('remarks', $mail->remarks) }}" /></div>
        <datalist id="offices">
            @foreach ($offices as $name)
                <option value="{{ $name }}"></option>
            @endforeach
        </datalist>
        <div class="flex flex-wrap gap-3 sm:col-span-2">
            <button class="btn-primary">Save changes</button>
            <a class="btn-secondary" href="{{ route('received-mails.index', ['month' => $mail->received_on->format('Y-m')]) }}">Cancel</a>
        </div>
    </form>

    <form method="post" action="{{ route('received-mails.destroy', $mail) }}" class="mt-6" data-confirm="Delete this mail from the log?">
        @csrf
        @method('DELETE')
        <button class="btn-danger btn-sm"><x-icon name="trash" class="size-3.5" /> Delete mail</button>
    </form>
</x-layouts.app>
