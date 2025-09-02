/**
 * Dashboard JavaScript - Sistema Protocolo Municipal
 * Versão Completa e Otimizada com correções e melhorias
 * Integração completa com backend PHP
 */
(function($) {
    'use strict';

    const Dashboard = {
        // Configurações
        config: {
            autoRefresh: false,
            refreshInterval: 300000, // 5 minutos
            charts: {},
            currentData: null,
            timelineOffset: 0,
            timelineLimit: 10,
            requestQueue: new Set(),
            retryAttempts: 3,
            retryDelay: 1000,
            cacheTimeout: 60000 // 1 minuto
        },

        // Cache para otimização
        cache: new Map(),

        // Estado da aplicação
        state: {
            isInitialized: false,
            isLoading: false,
            hasError: false,
            refreshInterval: null
        },

        // Handlers para cleanup
        handlers: {
            resize: null,
            keydown: null,
            beforeunload: null
        },

        // Inicialização
        init() {
            if (this.state.isInitialized) {
                console.warn('Dashboard já foi inicializado');
                return;
            }

            try {
                this.validateEnvironment();
                this.bindEvents();
                this.loadInitialData();
                this.setupAutoRefresh();
                this.setupVisibilityHandler();
                this.setupErrorHandling();
                
                this.state.isInitialized = true;
                console.log('📊 Dashboard inicializado com sucesso');
                
                // Dispatch evento customizado
                $(document).trigger('pmn:dashboard:initialized');
                
            } catch (error) {
                console.error('❌ Erro na inicialização:', error);
                this.showError('Erro ao inicializar dashboard');
                this.state.hasError = true;
            }
        },

        // Validação do ambiente
        validateEnvironment() {
            if (typeof pmnDashboard === 'undefined') {
                throw new Error('pmnDashboard não está definido');
            }
            
            if (typeof $ === 'undefined') {
                throw new Error('jQuery não está carregado');
            }

            // Verifica se elementos essenciais existem
            const requiredElements = ['#pmn-metrics', '.pmn-dashboard'];
            requiredElements.forEach(selector => {
                if (!$(selector).length) {
                    console.warn(`Elemento ${selector} não encontrado`);
                }
            });
        },

        // Event Listeners com cleanup
        bindEvents() {
            // Remove listeners anteriores se existirem
            this.unbindEvents();

            // Botão de refresh
            $(document).on('click.pmn-dashboard', '#pmn-refresh-data', (e) => {
                e.preventDefault();
                this.refreshData();
            });

            // Load more timeline
            $(document).on('click.pmn-dashboard', '#pmn-load-more-timeline', (e) => {
                e.preventDefault();
                this.loadMoreTimeline();
            });

            // Resize charts com debounce
            this.handlers.resize = this.debounce(() => {
                this.resizeCharts();
            }, 250);
            $(window).on('resize.pmn-dashboard', this.handlers.resize);

            // Keyboard shortcuts
            this.handlers.keydown = (e) => {
                // Ctrl/Cmd + R para refresh
                if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                    e.preventDefault();
                    this.refreshData();
                }
                // ESC para parar loading
                if (e.key === 'Escape' && this.state.isLoading) {
                    this.cancelRequests();
                }
            };
            $(document).on('keydown.pmn-dashboard', this.handlers.keydown);

            // Cleanup antes de sair da página
            this.handlers.beforeunload = () => {
                this.destroy();
            };
            $(window).on('beforeunload.pmn-dashboard', this.handlers.beforeunload);

            // Clicks em métricas para drill-down
            $(document).on('click.pmn-dashboard', '.pmn-metric-card', (e) => {
                const metric = $(e.currentTarget).find('[data-metric]').data('metric');
                this.handleMetricClick(metric);
            });
        },

        // Remove event listeners
        unbindEvents() {
            $(document).off('.pmn-dashboard');
            $(window).off('.pmn-dashboard');
        },

        // Carregamento inicial com retry
        async loadInitialData() {
            this.showLoading('Carregando dados iniciais...');
            
            let attempts = 0;
            const maxAttempts = this.config.retryAttempts;

            while (attempts < maxAttempts) {
                try {
                    const data = await this.fetchStats();
                    await this.processInitialData(data);
                    return;
                    
                } catch (error) {
                    attempts++;
                    console.error(`❌ Tentativa ${attempts} falhou:`, error);
                    
                    if (attempts >= maxAttempts) {
                        this.showError(`Erro após ${maxAttempts} tentativas: ${error.message}`);
                        return;
                    }
                    
                    // Aguarda antes da próxima tentativa
                    await this.delay(this.config.retryDelay * attempts);
                }
            }
        },

        // Processa dados iniciais
        async processInitialData(data) {
            if (!this.validateData(data)) {
                throw new Error('Dados inválidos recebidos');
            }

            // Atualiza interface em paralelo
            await Promise.all([
                this.updateMetrics(data.metrics, data.changes),
                this.initCharts(data.charts),
                this.loadTimeline(),
                this.checkAlerts(data)
            ]);
            
            this.config.currentData = data;
            this.hideLoading();
            
            console.log('✅ Dados processados:', data);
            $(document).trigger('pmn:dashboard:data-loaded', [data]);
        },

        // Refresh otimizado dos dados
        async refreshData(silent = false) {
            if (this.state.isLoading) {
                console.log('Refresh já em andamento, ignorando...');
                return;
            }

            const btn = $('#pmn-refresh-data');
            const icon = btn.find('.pmn-icon');
            
            if (!silent) {
                btn.addClass('loading').prop('disabled', true);
                icon.addClass('loading');
            }
            
            this.state.isLoading = true;

            try {
                const data = await this.fetchStats();
                
                if (!this.validateData(data)) {
                    throw new Error('Dados inválidos no refresh');
                }

                // Anima a atualização
                await this.animateRefresh(data);
                
                this.config.currentData = data;
                
                if (!silent) {
                    this.showToast('Dados atualizados', 'success');
                }
                
                $(document).trigger('pmn:dashboard:refreshed', [data]);
                
            } catch (error) {
                console.error('❌ Erro no refresh:', error);
                if (!silent) {
                    this.showToast(`Erro: ${error.message}`, 'error');
                }
                this.state.hasError = true;
                
            } finally {
                this.state.isLoading = false;
                if (!silent) {
                    btn.removeClass('loading').prop('disabled', false);
                    icon.removeClass('loading');
                }
            }
        },

        // Animação de refresh
        async animateRefresh(data) {
            // Anima métricas
            this.animateMetricsUpdate(data.metrics, data.changes);
            
            // Aguarda um pouco para sincronizar animações
            await this.delay(200);
            
            // Atualiza gráficos e timeline em paralelo
            await Promise.all([
                this.updateCharts(data.charts),
                this.refreshTimeline(),
                this.checkAlerts(data)
            ]);
        },

        // Fetch otimizado com cache
        async fetchStats(useCache = true) {
            const cacheKey = 'dashboard-stats';
            
            // Verifica cache
            if (useCache && this.cache.has(cacheKey)) {
                const cached = this.cache.get(cacheKey);
                if (Date.now() - cached.timestamp < this.config.cacheTimeout) {
                    console.log('📋 Usando dados do cache');
                    return cached.data;
                }
            }

            const requestId = `stats-${Date.now()}`;
            this.config.requestQueue.add(requestId);

            try {
                const response = await $.ajax({
                    url: pmnDashboard.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'pmn_dashboard_stats',
                        nonce: pmnDashboard.nonce,
                        cache_bust: Date.now()
                    },
                    timeout: 15000,
                    beforeSend: (xhr) => {
                        xhr.requestId = requestId;
                    }
                });

                if (!response.success) {
                    throw new Error(response.data?.message || 'Erro na resposta do servidor');
                }

                // Atualiza cache
                this.cache.set(cacheKey, {
                    data: response.data,
                    timestamp: Date.now()
                });

                return response.data;

            } finally {
                this.config.requestQueue.delete(requestId);
            }
        },

        // Validação robusta de dados
        validateData(data) {
            if (!data || typeof data !== 'object') {
                return false;
            }

            // Valida métricas
            if (!data.metrics || typeof data.metrics !== 'object') {
                console.warn('Métricas inválidas');
                return false;
            }

            // Valida valores numéricos
            const requiredMetrics = ['total', 'tramitacao', 'concluidos'];
            for (const metric of requiredMetrics) {
                const value = data.metrics[metric];
                if (typeof value !== 'number' || isNaN(value) || value < 0) {
                    console.warn(`Métrica ${metric} inválida:`, value);
                    return false;
                }
            }

            // Valida estrutura de gráficos
            if (data.charts) {
                const chartTypes = ['status', 'tipos', 'timeline'];
                for (const type of chartTypes) {
                    const chart = data.charts[type];
                    if (chart && (!Array.isArray(chart.labels) || !Array.isArray(chart.data))) {
                        console.warn(`Dados do gráfico ${type} inválidos`);
                        return false;
                    }
                }
            }

            return true;
        },

        // Atualização otimizada das métricas
        updateMetrics(metrics, changes) {
            if (!this.validateData({metrics, changes})) {
                console.warn('Dados de métricas inválidos');
                return Promise.resolve();
            }

            const promises = [];

            Object.keys(metrics).forEach(key => {
                const value = metrics[key];
                const change = changes?.[key];
                
                // Atualiza valor com animação
                const $value = $(`[data-metric="${key}"]`);
                if ($value.length) {
                    const currentValue = parseInt($value.text().replace(/\D/g, '')) || 0;
                    promises.push(this.animateNumber($value[0], currentValue, value));
                }
                
                // Atualiza mudança percentual
                if (change && typeof change === 'object') {
                    const $change = $(`[data-change="${key}"]`);
                    if ($change.length) {
                        $change
                            .removeClass('up down')
                            .addClass(change.direction)
                            .text(change.percent > 0 ? `${change.percent}%` : 'Sem mudança');
                    }
                }
            });

            return Promise.all(promises);
        },

        // Animação de atualização das métricas
        animateMetricsUpdate(metrics, changes) {
            const cards = $('.pmn-metric-card');
            
            // Adiciona classe de atualização com delay escalonado
            cards.each(function(index) {
                const $card = $(this);
                setTimeout(() => {
                    $card.addClass('pmn-updating');
                }, index * 100);
            });

            // Remove classe após animação
            setTimeout(() => {
                this.updateMetrics(metrics, changes);
                cards.removeClass('pmn-updating');
            }, cards.length * 100 + 200);
        },

        // Animação de números com easing
        animateNumber(element, from, to, duration = 1000) {
            return new Promise(resolve => {
                const start = Date.now();
                const update = () => {
                    const elapsed = Date.now() - start;
                    const progress = Math.min(elapsed / duration, 1);
                    const current = Math.round(from + (to - from) * this.easeOutQuart(progress));
                    
                    element.textContent = current.toLocaleString('pt-BR');
                    
                    if (progress < 1) {
                        requestAnimationFrame(update);
                    } else {
                        resolve();
                    }
                };
                update();
            });
        },

        // Inicialização otimizada dos gráficos
        initCharts(chartsData) {
            if (!window.Chart) {
                console.warn('Chart.js não carregado');
                return Promise.resolve();
            }

            // Configuração global otimizada
            Chart.defaults.font.family = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
            Chart.defaults.color = '#6B7280';
            Chart.defaults.borderColor = '#E2E8F0';
            Chart.defaults.responsive = true;
            Chart.defaults.maintainAspectRatio = false;

            const chartPromises = [];

            try {
                if (chartsData.status) {
                    chartPromises.push(this.initStatusChart(chartsData.status));
                }
                if (chartsData.tipos) {
                    chartPromises.push(this.initTipoChart(chartsData.tipos));
                }
                if (chartsData.timeline) {
                    chartPromises.push(this.initTimelineChart(chartsData.timeline));
                }

                return Promise.all(chartPromises);
                
            } catch (error) {
                console.error('Erro ao inicializar gráficos:', error);
                return Promise.resolve();
            }
        },

        // Gráfico de status otimizado
        initStatusChart(data) {
            return new Promise((resolve) => {
                const ctx = document.getElementById('pmn-status-chart');
                if (!ctx || !data) {
                    resolve();
                    return;
                }

                try {
                    // Destrói gráfico anterior se existir
                    if (this.config.charts.status) {
                        this.config.charts.status.destroy();
                    }

                    this.config.charts.status = new Chart(ctx, {
                        type: 'doughnut',
                        data: {
                            labels: data.labels || [],
                            datasets: [{
                                data: data.data || [],
                                backgroundColor: data.colors || ['#3B82F6', '#10B981', '#F59E0B', '#6B7280'],
                                borderWidth: 2,
                                borderColor: '#ffffff',
                                hoverBorderWidth: 3,
                                hoverOffset: 4
                            }]
                        },
                        options: {
                            plugins: {
                                legend: {
                                    position: 'bottom',
                                    labels: {
                                        padding: 20,
                                        usePointStyle: true,
                                        font: {
                                            size: 12
                                        }
                                    }
                                },
                                tooltip: {
                                    callbacks: {
                                        label: (context) => {
                                            const label = context.label || '';
                                            const value = context.parsed;
                                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                            return `${label}: ${value} (${percentage}%)`;
                                        }
                                    }
                                }
                            },
                            animation: {
                                animateRotate: true,
                                duration: 1000,
                                easing: 'easeOutQuart'
                            },
                            onClick: (event, elements) => {
                                if (elements.length > 0) {
                                    const index = elements[0].index;
                                    const status = data.labels[index];
                                    this.handleChartClick('status', status);
                                }
                            }
                        }
                    });

                    resolve();
                } catch (error) {
                    console.error('Erro no gráfico de status:', error);
                    resolve();
                }
            });
        },

        // Gráfico por tipo otimizado
        initTipoChart(data) {
            return new Promise((resolve) => {
                const ctx = document.getElementById('pmn-tipo-chart');
                if (!ctx || !data) {
                    resolve();
                    return;
                }

                try {
                    if (this.config.charts.tipo) {
                        this.config.charts.tipo.destroy();
                    }

                    this.config.charts.tipo = new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: data.labels || [],
                            datasets: [{
                                data: data.data || [],
                                backgroundColor: data.colors || ['#8B5CF6', '#06B6D4', '#84CC16', '#F97316', '#EF4444'],
                                borderRadius: 6,
                                borderSkipped: false
                            }]
                        },
                        options: {
                            indexAxis: 'y',
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    callbacks: {
                                        title: (context) => context[0].label,
                                        label: (context) => `Protocolos: ${context.parsed.x}`
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    },
                                    grid: {
                                        display: false
                                    }
                                },
                                y: {
                                    grid: {
                                        display: false
                                    }
                                }
                            },
                            animation: {
                                duration: 1000,
                                easing: 'easeOutQuart'
                            },
                            onClick: (event, elements) => {
                                if (elements.length > 0) {
                                    const index = elements[0].index;
                                    const tipo = data.labels[index];
                                    this.handleChartClick('tipo', tipo);
                                }
                            }
                        }
                    });

                    resolve();
                } catch (error) {
                    console.error('Erro no gráfico de tipos:', error);
                    resolve();
                }
            });
        },

        // Gráfico timeline otimizado
        initTimelineChart(data) {
            return new Promise((resolve) => {
                const ctx = document.getElementById('pmn-timeline-chart');
                if (!ctx || !data) {
                    resolve();
                    return;
                }

                try {
                    if (this.config.charts.timeline) {
                        this.config.charts.timeline.destroy();
                    }

                    // Formata datas para exibição
                    const labels = (data.labels || []).map(date => {
                        return new Date(date).toLocaleDateString('pt-BR', {
                            day: 'numeric',
                            month: 'short'
                        });
                    });

                    this.config.charts.timeline = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [{
                                label: 'Protocolos por dia',
                                data: data.data || [],
                                borderColor: '#2563EB',
                                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                                borderWidth: 3,
                                fill: true,
                                tension: 0.4,
                                pointBackgroundColor: '#2563EB',
                                pointBorderColor: '#ffffff',
                                pointBorderWidth: 2,
                                pointRadius: 4,
                                pointHoverRadius: 6
                            }]
                        },
                        options: {
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    mode: 'index',
                                    intersect: false,
                                    callbacks: {
                                        title: (context) => {
                                            if (data.labels && data.labels[context[0].dataIndex]) {
                                                const originalDate = data.labels[context[0].dataIndex];
                                                return new Date(originalDate).toLocaleDateString('pt-BR', {
                                                    weekday: 'long',
                                                    year: 'numeric',
                                                    month: 'long',
                                                    day: 'numeric'
                                                });
                                            }
                                            return '';
                                        },
                                        label: (context) => `Protocolos: ${context.parsed.y}`
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: {
                                        display: false
                                    }
                                },
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    }
                                }
                            },
                            interaction: {
                                mode: 'nearest',
                                axis: 'x',
                                intersect: false
                            },
                            animation: {
                                duration: 1500,
                                easing: 'easeOutQuart'
                            }
                        }
                    });

                    resolve();
                } catch (error) {
                    console.error('Erro no gráfico timeline:', error);
                    resolve();
                }
            });
        },

        // Atualização otimizada dos gráficos
        async updateCharts(chartsData) {
            if (!chartsData) return;

            const updates = [];

            // Status chart
            if (this.config.charts.status && chartsData.status) {
                updates.push(this.updateChart('status', chartsData.status));
            }

            // Tipo chart
            if (this.config.charts.tipo && chartsData.tipos) {
                updates.push(this.updateChart('tipo', chartsData.tipos));
            }

            // Timeline chart
            if (this.config.charts.timeline && chartsData.timeline) {
                updates.push(this.updateTimelineChart(chartsData.timeline));
            }

            await Promise.all(updates);
        },

        // Atualiza gráfico individual
        updateChart(chartName, data) {
            return new Promise((resolve) => {
                try {
                    const chart = this.config.charts[chartName];
                    if (!chart) {
                        resolve();
                        return;
                    }

                    chart.data.labels = data.labels || [];
                    chart.data.datasets[0].data = data.data || [];
                    
                    if (data.colors) {
                        chart.data.datasets[0].backgroundColor = data.colors;
                    }

                    chart.update('active');
                    resolve();
                } catch (error) {
                    console.error(`Erro ao atualizar gráfico ${chartName}:`, error);
                    resolve();
                }
            });
        },

        // Atualiza gráfico timeline
        updateTimelineChart(data) {
            return new Promise((resolve) => {
                try {
                    const chart = this.config.charts.timeline;
                    if (!chart || !data) {
                        resolve();
                        return;
                    }

                    const labels = (data.labels || []).map(date => {
                        return new Date(date).toLocaleDateString('pt-BR', {
                            day: 'numeric',
                            month: 'short'
                        });
                    });

                    chart.data.labels = labels;
                    chart.data.datasets[0].data = data.data || [];
                    chart.update('active');
                    resolve();
                } catch (error) {
                    console.error('Erro ao atualizar timeline:', error);
                    resolve();
                }
            });
        },

        // Timeline de atividades completa
        async loadTimeline() {
            try {
                const response = await $.ajax({
                    url: pmnDashboard.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'pmn_dashboard_timeline',
                        nonce: pmnDashboard.nonce,
                        limit: this.config.timelineLimit,
                        offset: 0
                    },
                    timeout: 10000
                });

                if (response.success && response.data) {
                    this.renderTimeline(response.data, true);
                } else {
                    throw new Error(response.data?.message || 'Dados de timeline inválidos');
                }
                
            } catch (error) {
                console.error('❌ Erro ao carregar timeline:', error);
                this.showTimelineError('Erro ao carregar atividades recentes');
            }
        },

        // Carrega mais itens da timeline
        async loadMoreTimeline() {
            if (this.state.isLoading) return;

            const btn = $('#pmn-load-more-timeline');
            btn.addClass('loading').prop('disabled', true);

            try {
                const response = await $.ajax({
                    url: pmnDashboard.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'pmn_dashboard_timeline',
                        nonce: pmnDashboard.nonce,
                        limit: this.config.timelineLimit,
                        offset: this.config.timelineOffset + this.config.timelineLimit
                    },
                    timeout: 10000
                });

                if (response.success && response.data && response.data.length > 0) {
                    this.renderTimeline(response.data, false);
                    this.config.timelineOffset += this.config.timelineLimit;
                    
                    // Esconde botão se não há mais dados
                    if (response.data.length < this.config.timelineLimit) {
                        btn.hide();
                    }
                } else {
                    btn.hide(); // Não há mais dados
                }
                
            } catch (error) {
                console.error('❌ Erro ao carregar mais timeline:', error);
                this.showToast('Erro ao carregar mais atividades', 'error');
            } finally {
                btn.removeClass('loading').prop('disabled', false);
            }
        },

        // Renderiza timeline
        renderTimeline(activities, replace = true) {
            const container = $('#pmn-recent-timeline');
            
            if (replace) {
                container.empty();
            }

            if (!Array.isArray(activities) || activities.length === 0) {
                if (replace) {
                    container.html('<p class="pmn-no-data">Nenhuma atividade recente encontrada</p>');
                }
                return;
            }

            const timeline = activities.map(activity => {
                const statusClass = this.getStatusClass(activity.status);
                const timeAgo = this.getTimeAgo(activity.data);
                
                return `
                    <div class="pmn-timeline-item" data-id="${activity.id}">
                        <div class="pmn-timeline-marker ${statusClass}"></div>
                        <div class="pmn-timeline-content">
                            <div class="pmn-timeline-header">
                                <strong>Protocolo ${activity.numero}</strong>
                                <span class="pmn-timeline-time">${timeAgo}</span>
                            </div>
                            <div class="pmn-timeline-body">
                                <p class="pmn-timeline-subject">${activity.assunto || 'Sem assunto'}</p>
                                <div class="pmn-timeline-meta">
                                    <span class="pmn-status ${statusClass}">${activity.status}</span>
                                    <span class="pmn-type">${activity.tipo_documento || 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            if (replace) {
                container.html(timeline);
            } else {
                container.append(timeline);
            }

            // Anima novos itens
            container.find('.pmn-timeline-item').each(function(index) {
                $(this).css('opacity', '0').delay(index * 50).animate({opacity: 1}, 300);
            });
        },

        // Refresh da timeline
        async refreshTimeline() {
            this.config.timelineOffset = 0;
            await this.loadTimeline();
        },

        // Handlers de eventos específicos
        handleMetricClick(metric) {
            console.log('Métrica clicada:', metric);
            $(document).trigger('pmn:metric:clicked', [metric]);
            
            // Aqui você pode implementar drill-down, filtros, etc.
            // Exemplo: redirecionar para página de detalhes
            if (metric === 'atrasados') {
                window.location.href = '/protocolos?status=atrasado';
            }
        },

        handleChartClick(chartType, value) {
            console.log('Gráfico clicado:', chartType, value);
            $(document).trigger('pmn:chart:clicked', [chartType, value]);
            
            // Implementar filtros baseados no clique
            // Exemplo: filtrar por status ou tipo
        },

        // Sistema de alertas
        async checkAlerts(data) {
            if (!data || !data.metrics) return;

            const alerts = [];
            const metrics = data.metrics;

            // Verifica protocolos atrasados
            if (metrics.atrasados > 0) {
                const urgency = metrics.atrasados > 10 ? 'high' : 'medium';
                alerts.push({
                    type: 'warning',
                    urgency: urgency,
                    title: 'Protocolos Atrasados',
                    message: `${metrics.atrasados} protocolo(s) estão atrasados`,
                    action: {
                        text: 'Ver Atrasados',
                        url: '/protocolos?status=atrasado'
                    }
                });
            }

            // Verifica alta carga de trabalho
            const totalAtivos = metrics.tramitacao + metrics.pendentes;
            if (totalAtivos > 100) {
                alerts.push({
                    type: 'info',
                    urgency: 'medium',
                    title: 'Alta Demanda',
                    message: `${totalAtivos} protocolos aguardando processamento`,
                    action: {
                        text: 'Ver Pendências',
                        url: '/protocolos?status=pendente,tramitacao'
                    }
                });
            }

            // Verifica mudanças significativas
            if (data.changes) {
                Object.entries(data.changes).forEach(([key, change]) => {
                    if (change.percent > 50 && change.direction === 'up' && key === 'atrasados') {
                        alerts.push({
                            type: 'error',
                            urgency: 'high',
                            title: 'Aumento Crítico',
                            message: `Protocolos atrasados aumentaram ${change.percent}%`,
                            action: {
                                text: 'Analisar',
                                url: '/relatorios?tipo=atrasos'
                            }
                        });
                    }
                });
            }

            this.renderAlerts(alerts);
        },

        // Renderiza alertas
        renderAlerts(alerts) {
            const container = $('#pmn-alerts');
            container.empty();

            if (!alerts.length) {
                container.hide();
                return;
            }

            const alertsHtml = alerts.map(alert => `
                <div class="pmn-alert pmn-alert-${alert.type} pmn-alert-${alert.urgency}" 
                     data-urgency="${alert.urgency}">
                    <div class="pmn-alert-icon">
                        ${this.getAlertIcon(alert.type)}
                    </div>
                    <div class="pmn-alert-content">
                        <h4 class="pmn-alert-title">${alert.title}</h4>
                        <p class="pmn-alert-message">${alert.message}</p>
                        ${alert.action ? `
                            <a href="${alert.action.url}" class="pmn-alert-action">
                                ${alert.action.text}
                            </a>
                        ` : ''}
                    </div>
                    <button class="pmn-alert-close" aria-label="Fechar alerta">×</button>
                </div>
            `).join('');

            container.html(alertsHtml).show();

            // Event listener para fechar alertas
            container.on('click', '.pmn-alert-close', function() {
                $(this).closest('.pmn-alert').fadeOut(300, function() {
                    $(this).remove();
                    if (!container.find('.pmn-alert').length) {
                        container.hide();
                    }
                });
            });

            // Auto-hide para alertas de baixa prioridade
            container.find('.pmn-alert[data-urgency="low"]').each(function() {
                setTimeout(() => {
                    $(this).fadeOut(300);
                }, 10000);
            });
        },

        // Auto-refresh inteligente
        setupAutoRefresh() {
            if (!pmnDashboard.autoRefresh || pmnDashboard.autoRefresh <= 0) {
                return;
            }

            this.config.autoRefresh = true;
            this.config.refreshInterval = parseInt(pmnDashboard.autoRefresh);

            this.state.refreshInterval = setInterval(() => {
                // Só faz refresh se a página estiver visível
                if (!document.hidden && !this.state.isLoading) {
                    this.refreshData(true); // silent refresh
                }
            }, this.config.refreshInterval);

            console.log(`🔄 Auto-refresh ativado: ${this.config.refreshInterval}ms`);
        },

        // Gerenciamento de visibilidade
        setupVisibilityHandler() {
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    // Pausa operações quando não visível
                    this.pauseOperations();
                } else {
                    // Resume operações e faz refresh
                    this.resumeOperations();
                }
            });
        },

        // Pausa operações
        pauseOperations() {
            if (this.state.refreshInterval) {
                clearInterval(this.state.refreshInterval);
            }
            this.cancelRequests();
        },

        // Resume operações
        resumeOperations() {
            if (this.config.autoRefresh) {
                this.setupAutoRefresh();
                // Faz um refresh imediato mas silencioso
                setTimeout(() => {
                    this.refreshData(true);
                }, 1000);
            }
        },

        // Cancela requisições em andamento
        cancelRequests() {
            // Cancela requisições AJAX em andamento
            this.config.requestQueue.forEach(requestId => {
                // jQuery não tem cancelamento nativo, mas podemos ignorar respostas
                console.log(`Cancelando requisição: ${requestId}`);
            });
            this.config.requestQueue.clear();
        },

        // Redimensiona gráficos
        resizeCharts() {
            try {
                Object.values(this.config.charts).forEach(chart => {
                    if (chart && typeof chart.resize === 'function') {
                        chart.resize();
                    }
                });
            } catch (error) {
                console.error('Erro ao redimensionar gráficos:', error);
            }
        },

        // Setup de tratamento de erros
        setupErrorHandling() {
            // Captura erros globais do JavaScript
            window.addEventListener('error', (event) => {
                if (event.filename && event.filename.includes('dashboard.js')) {
                    console.error('Erro no dashboard:', event.error);
                    this.showError('Erro inesperado no dashboard');
                }
            });

            // Captura erros de promises não tratadas
            window.addEventListener('unhandledrejection', (event) => {
                console.error('Promise rejeitada:', event.reason);
                if (event.reason && event.reason.message) {
                    this.showError(`Erro: ${event.reason.message}`);
                }
            });
        },

        // Utilitários de interface
        showLoading(message = 'Carregando...') {
            const overlay = $('#pmn-loading-overlay');
            if (overlay.length) {
                overlay.find('p').text(message);
                overlay.fadeIn(200);
            }
            
            // Adiciona classe de loading aos elementos principais
            $('.pmn-metric-card, .pmn-chart-card').addClass('pmn-loading');
        },

        hideLoading() {
            $('#pmn-loading-overlay').fadeOut(200);
            $('.pmn-metric-card, .pmn-chart-card').removeClass('pmn-loading');
        },

        showError(message, duration = 5000) {
            this.hideLoading();
            this.showToast(message, 'error', duration);
            
            // Log detalhado para debug
            console.error('Dashboard Error:', {
                message,
                timestamp: new Date().toISOString(),
                state: this.state,
                config: this.config
            });
        },

        showTimelineError(message) {
            const container = $('#pmn-recent-timeline');
            container.html(`
                <div class="pmn-timeline-error">
                    <div class="pmn-error-icon">⚠️</div>
                    <div class="pmn-error-message">${message}</div>
                    <button class="pmn-btn pmn-btn-sm" onclick="Dashboard.loadTimeline()">
                        Tentar Novamente
                    </button>
                </div>
            `);
        },

        showToast(message, type = 'info', duration = 3000) {
            // Remove toasts anteriores
            $('.pmn-toast').remove();

            const toast = $(`
                <div class="pmn-toast pmn-toast-${type}" style="display: none;">
                    <div class="pmn-toast-content">
                        <span class="pmn-toast-icon">${this.getToastIcon(type)}</span>
                        <span class="pmn-toast-message">${message}</span>
                    </div>
                    <button class="pmn-toast-close">×</button>
                </div>
            `);

            $('body').append(toast);
            toast.fadeIn(200);

            // Auto-hide
            setTimeout(() => {
                toast.fadeOut(200, () => toast.remove());
            }, duration);

            // Close button
            toast.on('click', '.pmn-toast-close', () => {
                toast.fadeOut(200, () => toast.remove());
            });
        },

        // Utilitários de dados
        getStatusClass(status) {
            const classes = {
                'Em tramitação': 'status-progress',
                'Concluído': 'status-success',
                'Pendente': 'status-warning',
                'Arquivado': 'status-archived',
                'Atrasado': 'status-error'
            };
            return classes[status] || 'status-default';
        },

        getTimeAgo(dateString) {
            if (!dateString) return 'Data inválida';

            try {
                const date = new Date(dateString);
                const now = new Date();
                const diffMs = now - date;
                const diffMinutes = Math.floor(diffMs / (1000 * 60));
                const diffHours = Math.floor(diffMinutes / 60);
                const diffDays = Math.floor(diffHours / 24);

                if (diffMinutes < 1) return 'Agora mesmo';
                if (diffMinutes < 60) return `${diffMinutes} min atrás`;
                if (diffHours < 24) return `${diffHours}h atrás`;
                if (diffDays < 7) return `${diffDays} dia(s) atrás`;
                
                return date.toLocaleDateString('pt-BR');
            } catch (error) {
                console.warn('Erro ao calcular tempo:', error);
                return 'Data inválida';
            }
        },

        getAlertIcon(type) {
            const icons = {
                'error': '🚨',
                'warning': '⚠️',
                'info': 'ℹ️',
                'success': '✅'
            };
            return icons[type] || 'ℹ️';
        },

        getToastIcon(type) {
            const icons = {
                'error': '❌',
                'warning': '⚠️',
                'info': 'ℹ️',
                'success': '✅'
            };
            return icons[type] || 'ℹ️';
        },

        // Funções utilitárias
        debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func.apply(this, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        },

        easeOutQuart(t) {
            return 1 - (--t) * t * t * t;
        },

        delay(ms) {
            return new Promise(resolve => setTimeout(resolve, ms));
        },

        // Cache management
        clearCache() {
            this.cache.clear();
            console.log('Cache limpo');
        },

        getCacheStats() {
            return {
                size: this.cache.size,
                keys: Array.from(this.cache.keys()),
                timeout: this.config.cacheTimeout
            };
        },

        // Cleanup completo
        destroy() {
            console.log('🧹 Destruindo dashboard...');

            // Para auto-refresh
            if (this.state.refreshInterval) {
                clearInterval(this.state.refreshInterval);
            }

            // Cancela requisições
            this.cancelRequests();

            // Remove event listeners
            this.unbindEvents();

            // Destrói gráficos
            Object.values(this.config.charts).forEach(chart => {
                if (chart && typeof chart.destroy === 'function') {
                    try {
                        chart.destroy();
                    } catch (error) {
                        console.warn('Erro ao destruir gráfico:', error);
                    }
                }
            });

            // Limpa cache
            this.clearCache();

            // Remove toasts e overlays
            $('.pmn-toast, #pmn-loading-overlay').remove();

            // Reset state
            this.state = {
                isInitialized: false,
                isLoading: false,
                hasError: false,
                refreshInterval: null
            };

            this.config.charts = {};
            this.config.currentData = null;

            console.log('✅ Dashboard destruído');
        },

        // Métodos públicos para debug
        getState() {
            return {
                state: this.state,
                config: this.config,
                cache: this.getCacheStats()
            };
        },

        // Força refresh manual
        forceRefresh() {
            this.clearCache();
            return this.refreshData(false);
        }
    };

    // Extensões do Dashboard para funcionalidades específicas
    Dashboard.Extensions = {
        // Exportação de dados
        exportData(format = 'json') {
            const data = Dashboard.config.currentData;
            if (!data) {
                Dashboard.showToast('Nenhum dado para exportar', 'warning');
                return;
            }

            let exportData;
            let filename;
            let mimeType;

            switch (format) {
                case 'json':
                    exportData = JSON.stringify(data, null, 2);
                    filename = `dashboard-${new Date().toISOString().split('T')[0]}.json`;
                    mimeType = 'application/json';
                    break;
                
                case 'csv':
                    const metrics = data.metrics;
                    const csvHeaders = 'Métrica,Valor\n';
                    const csvData = Object.entries(metrics)
                        .map(([key, value]) => `${key},${value}`)
                        .join('\n');
                    exportData = csvHeaders + csvData;
                    filename = `dashboard-${new Date().toISOString().split('T')[0]}.csv`;
                    mimeType = 'text/csv';
                    break;
                
                default:
                    Dashboard.showToast('Formato não suportado', 'error');
                    return;
            }

            // Cria e dispara download
            const blob = new Blob([exportData], { type: mimeType });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            Dashboard.showToast(`Dados exportados: ${filename}`, 'success');
        },

        // Print do dashboard
        print() {
            window.print();
        },

        // Fullscreen para gráficos
        toggleFullscreen(chartId) {
            const chart = Dashboard.config.charts[chartId];
            const container = $(`#${chartId}`).closest('.pmn-chart-card');
            
            if (!chart || !container.length) return;

            container.toggleClass('pmn-chart-fullscreen');
            
            setTimeout(() => {
                chart.resize();
            }, 300);
        }
    };

    // Inicialização automática quando DOM estiver pronto
    $(document).ready(() => {
        // Verifica se está em página do dashboard
        if ($('.pmn-dashboard').length) {
            Dashboard.init();
        }
    });

    // Exposição global para debug e extensões
    window.Dashboard = Dashboard;

    // Event listeners para cleanup
    $(window).on('beforeunload', () => {
        Dashboard.destroy();
    });

    // Reinicialização em páginas SPA
    $(document).on('pmn:page:loaded', () => {
        if ($('.pmn-dashboard').length && !Dashboard.state.isInitialized) {
            Dashboard.init();
        }
    });

})(jQuery);