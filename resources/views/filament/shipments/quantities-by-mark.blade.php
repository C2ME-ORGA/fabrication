@php
    /** @var \Illuminate\Support\Collection<int, array{mark: string, designation: string, accepted: int, prepared: int, packaged: int, remaining_to_package: int, remaining_to_prepare: int}> $rows */
@endphp

@if ($rows->isEmpty())
    <span class="text-sm text-gray-500">Aucune ligne de préparation.</span>
@else
    <table class="w-full text-sm">
        <thead>
            <tr>
                <th class="text-left">Repère</th>
                <th class="text-left">Désignation</th>
                <th class="text-right">Accepté OF</th>
                <th class="text-right">Préparé</th>
                <th class="text-right">Colisé</th>
                <th class="text-right">Reste à coliser</th>
                <th class="text-right">Reste à préparer sur l’OF</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $row['mark'] }}</td>
                    <td>{{ $row['designation'] }}</td>
                    <td class="text-right">{{ $row['accepted'] }}</td>
                    <td class="text-right">{{ $row['prepared'] }}</td>
                    <td class="text-right">{{ $row['packaged'] }}</td>
                    <td class="text-right">{{ $row['remaining_to_package'] }}</td>
                    <td class="text-right">{{ $row['remaining_to_prepare'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
