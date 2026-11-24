<table>
    <tr><th>Indicador</th><th class="r">Realizado</th><th class="r">Meta</th><th>Situação</th><th>Tendência (3 meses)</th><th>Responsável</th></tr>
    @foreach ($linhas as $linha)
        <tr>
            <td>{{ $linha['codigo'] }} {{ $linha['nome'] }}</td>
            <td class="r"><b>{{ $linha['realizado_fmt'] }}</b></td>
            <td class="r">{{ $linha['meta_fmt'] }}</td>
            <td class="{{ $linha['situacao'] }}">{{ $linha['situacao_rotulo'] }}</td>
            <td>{{ collect($linha['tendencia'])->pluck('fmt')->implode(' › ') }}</td>
            <td>{{ $linha['cargo'] }}</td>
        </tr>
    @endforeach
</table>
