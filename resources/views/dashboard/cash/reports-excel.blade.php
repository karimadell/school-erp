<table>
    <thead>
        <tr>
            <th colspan="6" style="font-weight:bold;">
                {{ $title }}
            </th>
        </tr>
        <tr>
            <th>#</th>
            <th>Account</th>
            <th>Type</th>
            <th>Amount</th>
            <th>Description</th>
            <th>Date</th>
        </tr>
    </thead>
    <tbody>
        @foreach($transactions as $t)
            <tr>
                <td>{{ $t->id }}</td>
                <td>{{ $t->cashAccount->name ?? '-' }}</td>
                <td>{{ strtoupper($t->type) }}</td>
                <td>{{ number_format($t->amount, 2, '.', '') }}</td>
                <td>{{ $t->description }}</td>
                <td>{{ $t->created_at->format('Y-m-d H:i') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>