<div>
    {{-- Filtres compacts --}}
    <div class="flex items-center gap-3 mb-5 flex-wrap">
        <select wire:model.live="machineId"
                class="bg-app-elevated border border-app-border text-ink-primary rounded-md px-3 py-1.5 text-sm focus:outline-none focus:border-accent focus:ring-2 focus:ring-accent/20">
            <option value="">Tous les hélicos</option>
            @foreach ($machines as $m)
                <option value="{{ $m->id }}">{{ $m->hc_id }}</option>
            @endforeach
        </select>
        <div class="flex items-center gap-2 bg-app-card border border-app-border rounded-md px-3 py-1">
            <input type="date" wire:model.live="startDate"
                   class="bg-transparent border-0 text-ink-primary font-mono text-xs px-0 py-1 focus:outline-none focus:ring-0 w-32" />
            <span class="text-ink-muted text-sm">→</span>
            <input type="date" wire:model.live="endDate"
                   class="bg-transparent border-0 text-ink-primary font-mono text-xs px-0 py-1 focus:outline-none focus:ring-0 w-32" />
        </div>
        @if ($machineId && $startDate && $endDate)
            <x-primary-button wire:click="displayChart">
                Afficher
            </x-primary-button>
            <x-secondary-button wire:click="clearFilter">
                ✕ Réinitialiser
            </x-secondary-button>
        @endif
        @error('machineId')<span class="text-xs text-danger">{{ $message }}</span>@enderror
        @error('startDate')<span class="text-xs text-danger">{{ $message }}</span>@enderror
        @error('endDate')<span class="text-xs text-danger">{{ $message }}</span>@enderror
    </div>

    {{-- KPI cards (toujours visibles) --}}
    <div class="grid grid-cols-4 gap-3 mb-5">
        <x-card class="px-5 py-4">
            <x-section-label class="mb-1.5">Total vols</x-section-label>
            <div class="font-mono text-[28px] font-medium text-ink-primary tabular-nums">{{ $kpis['total_vols'] }}</div>
        </x-card>
        <x-card class="px-5 py-4">
            <x-section-label class="mb-1.5">Pannes conservées</x-section-label>
            <div class="font-mono text-[28px] font-medium text-accent tabular-nums">{{ number_format($kpis['total_conservees'], 0, ',', ' ') }}</div>
        </x-card>
        <x-card class="px-5 py-4">
            <x-section-label class="mb-1.5">Taux validation</x-section-label>
            <div class="font-mono text-[28px] font-medium text-ok tabular-nums">{{ $kpis['taux_validation'] }}%</div>
        </x-card>
        <x-card class="px-5 py-4">
            <x-section-label class="mb-1.5">Erreurs pipeline</x-section-label>
            <div class="font-mono text-[28px] font-medium text-danger tabular-nums">{{ $kpis['erreurs_pipeline'] }}</div>
        </x-card>
    </div>

    @if ($isFiltered)
        {{-- Vue filtrée : chart pannes + FH par semaine pour 1 machine --}}
        <x-card class="p-5"
                wire:ignore.self
                x-data="dashboardChart(@js($chartData))"
                x-init="render($wire.chartData)"
                x-on:chart-data-updated.window="render($event.detail.data || $event.detail[0].data)">
            <div class="text-sm font-semibold text-ink-primary mb-1">{{ $chartTitle }}</div>
            <div class="text-[11px] text-ink-muted mb-3">Pannes et heures de vol par semaine ISO</div>
            <div wire:ignore id="dashboard-chart-container" style="min-height: 380px;"></div>
        </x-card>
    @else
        {{-- Vue défaut : chart mensuel multi-machines + widget validation --}}
        <x-card class="p-5 mb-4">
            <div class="flex items-start justify-between flex-wrap gap-3 mb-3">
                <div>
                    <div class="text-sm font-semibold text-ink-primary mb-0.5">Heures de vol par mois</div>
                    <div class="text-[11px] text-ink-muted">{{ count($monthlyData['months'] ?? []) }} derniers mois · machines actives uniquement</div>
                </div>
                @if (!empty($monthlyData['series']))
                    <div class="flex flex-wrap items-center gap-3">
                        @foreach ($monthlyData['series'] as $s)
                            <div class="flex items-center gap-1.5">
                                <div class="w-2.5 h-2.5 rounded-sm" style="background:{{ $s['color'] }}"></div>
                                <span class="text-[10px] font-mono text-ink-secondary">{{ $s['name'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
            @if (empty($monthlyData['series']))
                <div class="py-12 text-center text-ink-muted text-sm">
                    Aucune machine active sur les 30 derniers jours.
                </div>
            @else
                <div wire:ignore id="monthly-chart-container"
                     x-data x-init="renderMonthlyChart(@js($monthlyData))"
                     style="min-height: 280px;"></div>
            @endif
        </x-card>

        @if (!empty($validationBreakdown))
            <x-card class="p-5">
                <div class="flex items-start justify-between flex-wrap gap-3 mb-4">
                    <div>
                        <div class="text-sm font-semibold text-ink-primary mb-0.5">Statuts de validation des pannes</div>
                        <div class="text-[11px] text-ink-muted">Par appareil actif</div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5"><div class="w-2.5 h-1.5 rounded-sm bg-ok"></div><span class="text-[10px] text-ink-secondary">Validées</span></div>
                        <div class="flex items-center gap-1.5"><div class="w-2.5 h-1.5 rounded-sm bg-danger"></div><span class="text-[10px] text-ink-secondary">Rejetées</span></div>
                        <div class="flex items-center gap-1.5"><div class="w-2.5 h-1.5 rounded-sm bg-accent"></div><span class="text-[10px] text-ink-secondary">Manquantes</span></div>
                    </div>
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-3">
                    @foreach ($validationBreakdown as $row)
                        <div class="px-3 py-3 bg-app-bg border border-app-border-soft rounded-md">
                            <div class="font-mono text-sm font-medium text-accent-pressed mb-2">{{ $row['hc_id'] }}</div>
                            <div class="h-1.5 bg-app-border-soft rounded overflow-hidden flex mb-2.5">
                                @if ($row['total'] > 0)
                                    <div class="h-full bg-ok" style="width:{{ $row['pct_v'] }}%"></div>
                                    <div class="h-full bg-danger" style="width:{{ $row['pct_r'] }}%"></div>
                                    <div class="h-full bg-accent" style="width:{{ $row['pct_m'] }}%"></div>
                                @endif
                            </div>
                            <div class="space-y-0.5 text-[10px] font-mono">
                                <div class="flex justify-between"><span class="text-ink-muted">Val.</span><span class="text-ok font-medium">{{ $row['validated'] }}</span></div>
                                <div class="flex justify-between"><span class="text-ink-muted">Rej.</span><span class="text-danger font-medium">{{ $row['rejected'] }}</span></div>
                                <div class="flex justify-between"><span class="text-ink-muted">Manq.</span><span class="text-warn font-medium">{{ $row['missing'] }}</span></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>
        @endif
    @endif

    @once
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
        <script>
            window.dashboardChart = function (initial) {
                return {
                    chart: null,
                    render(data) {
                        if (!data || !data.weeks || data.weeks.length === 0) return;
                        const noDataAnnotations = (data.noData || []).map(w => ({
                            x: w,
                            borderColor: '#f5b731',
                            fillColor: '#fef9eb',
                            opacity: 0.3,
                            label: { text: 'NO DATA', borderColor: 'transparent',
                                style: { color: '#a07010', background: '#fff', fontSize: '10px', fontFamily: 'DM Mono' } },
                        }));
                        const options = {
                            chart: { type: 'line', height: 380, toolbar: { show: true }, fontFamily: 'DM Sans', background: 'transparent' },
                            series: [
                                { name: 'Pannes',        type: 'line', data: data.pannes },
                                { name: 'Heures de vol', type: 'line', data: data.fh },
                            ],
                            stroke: { width: 2.5, curve: 'smooth' },
                            colors: ['#c0391a', '#f5b731'],
                            markers: { size: 4 },
                            xaxis: {
                                categories: data.weeks,
                                labels: { rotate: -45, style: { fontSize: '10px', fontFamily: 'DM Mono', colors: '#9aa3b8' } },
                                axisBorder: { color: '#dde2ec' },
                                axisTicks: { color: '#dde2ec' },
                            },
                            yaxis: [
                                { seriesName: 'Pannes', title: { text: 'Pannes', style: { color: '#c0391a', fontSize: '11px', fontFamily: 'DM Sans' } },
                                  labels: { style: { colors: '#9aa3b8', fontFamily: 'DM Mono', fontSize: '10px' } }, min: 0 },
                                { seriesName: 'Heures de vol', opposite: true, title: { text: 'Heures', style: { color: '#a07010', fontSize: '11px', fontFamily: 'DM Sans' } },
                                  labels: { style: { colors: '#9aa3b8', fontFamily: 'DM Mono', fontSize: '10px' } }, min: 0 },
                            ],
                            annotations: { xaxis: noDataAnnotations },
                            legend: { position: 'top', horizontalAlign: 'left', fontSize: '11px', fontFamily: 'DM Sans', labels: { colors: '#5a6682' } },
                            tooltip: { shared: true, intersect: false, theme: 'light' },
                            grid: { borderColor: '#dde2ec', strokeDashArray: 3 },
                        };
                        this.$nextTick(() => {
                            const el = document.querySelector('#dashboard-chart-container');
                            if (!el) return;
                            if (this.chart) this.chart.destroy();
                            this.chart = new ApexCharts(el, options);
                            this.chart.render();
                        });
                    },
                };
            };

            window.renderMonthlyChart = function (data) {
                if (!data.series || data.series.length === 0) return;
                const options = {
                    chart: { type: 'bar', height: 280, toolbar: { show: false }, fontFamily: 'DM Sans', background: 'transparent', stacked: false },
                    series: data.series.map(s => ({ name: s.name, data: s.data })),
                    colors: data.series.map(s => s.color),
                    plotOptions: { bar: { borderRadius: 2, columnWidth: '70%' } },
                    dataLabels: { enabled: false },
                    xaxis: {
                        categories: data.months,
                        labels: { style: { fontFamily: 'DM Mono', fontSize: '10px', colors: '#9aa3b8' } },
                        axisBorder: { color: '#dde2ec' },
                        axisTicks: { color: '#dde2ec' },
                    },
                    yaxis: {
                        title: { text: 'Heures', style: { color: '#5a6682', fontSize: '11px', fontFamily: 'DM Sans' } },
                        labels: { style: { fontFamily: 'DM Mono', fontSize: '10px', colors: '#9aa3b8' }, formatter: (v) => v + 'h' },
                        min: 0,
                    },
                    legend: { show: false },
                    tooltip: { theme: 'light', y: { formatter: (v) => v + 'h' } },
                    grid: { borderColor: '#dde2ec', strokeDashArray: 3 },
                };
                const el = document.querySelector('#monthly-chart-container');
                if (!el) return;
                const chart = new ApexCharts(el, options);
                chart.render();
            };
        </script>
    @endonce
</div>
