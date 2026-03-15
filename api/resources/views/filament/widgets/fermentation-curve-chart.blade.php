<x-filament-widgets::widget>
    <x-filament::section>
        @if ($hasData)
            <div
                x-data="fermentationChart(@js($chartData))"
                x-init="initChart()"
                class="w-full"
            >
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">
                    {{ $chartData['title'] ?? 'Fermentation Curve' }}
                </h3>

                <div class="relative" style="height: 400px;">
                    <canvas x-ref="chartCanvas"></canvas>
                </div>
            </div>

            <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

            <script>
                document.addEventListener('alpine:init', () => {
                    Alpine.data('fermentationChart', (chartData) => ({
                        chart: null,

                        initChart() {
                            const ctx = this.$refs.chartCanvas.getContext('2d');

                            const datasets = [
                                {
                                    label: chartData.leftLabel || 'Brix',
                                    data: chartData.brix,
                                    borderColor: 'rgb(59, 130, 246)',
                                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                    borderWidth: 2,
                                    fill: true,
                                    tension: 0.3,
                                    yAxisID: 'y',
                                    pointRadius: 4,
                                    pointHoverRadius: 6,
                                },
                                {
                                    label: 'Temperature (°F)',
                                    data: chartData.temperature,
                                    borderColor: 'rgb(239, 68, 68)',
                                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                                    borderWidth: 2,
                                    borderDash: [5, 5],
                                    fill: false,
                                    tension: 0.3,
                                    yAxisID: 'y1',
                                    pointRadius: 4,
                                    pointHoverRadius: 6,
                                },
                            ];

                            // Add target temperature line if set
                            if (chartData.targetTemp !== null) {
                                const targetLine = new Array(chartData.labels.length).fill(chartData.targetTemp);
                                datasets.push({
                                    label: 'Target Temp',
                                    data: targetLine,
                                    borderColor: 'rgba(239, 68, 68, 0.4)',
                                    borderWidth: 1,
                                    borderDash: [2, 4],
                                    fill: false,
                                    pointRadius: 0,
                                    pointHoverRadius: 0,
                                    yAxisID: 'y1',
                                });
                            }

                            this.chart = new Chart(ctx, {
                                type: 'line',
                                data: {
                                    labels: chartData.labels,
                                    datasets: datasets,
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    interaction: {
                                        mode: 'index',
                                        intersect: false,
                                    },
                                    plugins: {
                                        tooltip: {
                                            callbacks: {
                                                label: function(context) {
                                                    let label = context.dataset.label || '';
                                                    if (context.parsed.y !== null) {
                                                        label += ': ' + context.parsed.y.toFixed(1);
                                                    }
                                                    return label;
                                                }
                                            }
                                        },
                                        legend: {
                                            position: 'bottom',
                                        },
                                    },
                                    scales: {
                                        x: {
                                            display: true,
                                            title: {
                                                display: true,
                                                text: 'Date',
                                            },
                                            grid: {
                                                display: false,
                                            },
                                        },
                                        y: {
                                            type: 'linear',
                                            display: true,
                                            position: 'left',
                                            title: {
                                                display: true,
                                                text: chartData.leftLabel || 'Brix',
                                                color: 'rgb(59, 130, 246)',
                                            },
                                            ticks: {
                                                color: 'rgb(59, 130, 246)',
                                            },
                                        },
                                        y1: {
                                            type: 'linear',
                                            display: true,
                                            position: 'right',
                                            title: {
                                                display: true,
                                                text: 'Temperature (°F)',
                                                color: 'rgb(239, 68, 68)',
                                            },
                                            ticks: {
                                                color: 'rgb(239, 68, 68)',
                                            },
                                            grid: {
                                                drawOnChartArea: false,
                                            },
                                        },
                                    },
                                },
                            });
                        },
                    }));
                });
            </script>
        @else
            <div class="flex items-center justify-center py-12 text-gray-500 dark:text-gray-400">
                <div class="text-center">
                    <x-heroicon-o-chart-bar class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-semibold">No fermentation data</h3>
                    <p class="mt-1 text-sm">Start adding daily entries to see the fermentation curve.</p>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
