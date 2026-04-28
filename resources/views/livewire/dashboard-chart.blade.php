<div>
    <div class="bg-white shadow rounded-xl border border-slate-200 p-6 mb-6">
        <div class="grid md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm mb-1 font-medium text-slate-700">Machine</label>
                <select wire:model="machineId" class="border border-slate-300 rounded-lg w-full px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <option value="">— Selectionner —</option>
                    @foreach ($machines as $m)
                        <option value="{{ $m->id }}">{{ $m->hc_id }}</option>
                    @endforeach
                </select>
                @error('machineId') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm mb-1 font-medium text-slate-700">Date debut</label>
                <input type="date" wire:model="startDate" class="border border-slate-300 rounded-lg w-full px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                @error('startDate') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="block text-sm mb-1 font-medium text-slate-700">Date fin</label>
                <input type="date" wire:model="endDate" class="border border-slate-300 rounded-lg w-full px-3 py-2 text-sm focus:border-blue-500 focus:ring-1 focus:ring-blue-500" />
                @error('endDate') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <button wire:click="displayChart"
                        wire:loading.attr="disabled"
                        class="bg-blue-600 text-white px-4 py-2 rounded-lg w-full hover:bg-blue-700 transition text-sm font-medium">
                    <span wire:loading.remove wire:target="displayChart">Afficher le dashboard</span>
                    <span wire:loading wire:target="displayChart">Chargement...</span>
                </button>
            </div>
        </div>
    </div>

    <div wire:ignore.self
         x-data="dashboardChart(@js($chartData))"
         x-init="render($wire.chartData)"
         x-show="visible"
         x-on:chart-data-updated.window="render($event.detail.data || $event.detail[0].data)"
         class="bg-white shadow rounded-xl border border-slate-200 p-6">
        <div wire:ignore id="dashboard-chart-container" style="min-height: 420px;"></div>
    </div>

    @once
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
        <script>
            window.dashboardChart = function (initial) {
                return {
                    visible: initial && initial.weeks && initial.weeks.length > 0,
                    chart: null,

                    render(data) {
                        if (!data || !data.weeks || data.weeks.length === 0) {
                            this.visible = false;
                            return;
                        }
                        this.visible = true;

                        const noDataAnnotations = (data.noData || []).map(w => ({
                            x: w,
                            borderColor: '#f87171',
                            fillColor: '#fee2e2',
                            opacity: 0.3,
                            label: {
                                text: 'NO DATA',
                                borderColor: 'transparent',
                                style: { color: '#b91c1c', background: '#fff', fontSize: '10px' },
                            },
                        }));

                        const options = {
                            chart: { type: 'line', height: 420, toolbar: { show: true }, zoom: { enabled: true }, fontFamily: 'inherit' },
                            series: [
                                { name: 'Pannes',        type: 'line', data: data.pannes },
                                { name: 'Heures de vol', type: 'line', data: data.fh },
                            ],
                            stroke: { width: 2.5, curve: 'smooth' },
                            colors: ['#2563eb', '#dc2626'],
                            markers: { size: 5 },
                            xaxis: {
                                categories: data.weeks,
                                labels: { rotate: -45, style: { fontSize: '11px' } },
                                title: { text: 'Semaines ISO' },
                            },
                            yaxis: [
                                {
                                    seriesName: 'Pannes',
                                    title: { text: 'Nombre de pannes', style: { color: '#2563eb' } },
                                    labels: { style: { colors: '#2563eb' } },
                                    min: 0,
                                },
                                {
                                    seriesName: 'Heures de vol',
                                    opposite: true,
                                    title: { text: 'Heures de vol', style: { color: '#dc2626' } },
                                    labels: { style: { colors: '#dc2626' } },
                                    min: 0,
                                },
                            ],
                            title: { text: data.title, align: 'left', style: { fontSize: '14px', fontWeight: 600 } },
                            annotations: { xaxis: noDataAnnotations },
                            legend: { position: 'top', horizontalAlign: 'left' },
                            tooltip: { shared: true, intersect: false },
                            grid: { strokeDashArray: 3 },
                        };

                        this.$nextTick(() => {
                            const el = document.querySelector('#dashboard-chart-container');
                            if (!el) return;
                            if (this.chart) {
                                this.chart.destroy();
                            }
                            this.chart = new ApexCharts(el, options);
                            this.chart.render();
                        });
                    },
                };
            };
        </script>
    @endonce
</div>
