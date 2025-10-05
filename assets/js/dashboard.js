/**
 * Dashboard Otimizado - Versão Performance
 * 
 * Melhorias:
 * - Debounce em eventos
 * - Cache mais longo
 * - Lazy loading de timeline
 * - Throttle em scroll/resize
 */
(function($) {
    'use strict';

    const Dashboard = {
        config: {
            autoRefresh: false,
            refreshInterval: 600000, // 10 min (era 5)
            charts: {},
            currentData: null,
            cacheTimeout: 600000, // 10 min (era 1 min!)
            requestQueue: new Set(),
        },

        cache: new Map(),
        state: {
            isInitialized: false,
            isLoading: false,
        },

        init() {
            if (this.state.isInitialized) return;

            try {
                this.validateEnvironment();
                this.bindEvents();
                this.loadInitialData();
                this.setupAutoRefresh();
                
                this.state.isInitialized = true;
                console.log('📊 Dashboard inicializado (modo performance)');
                
            } catch (error) {
                console.error('❌ Erro na inicialização:', error);
                this.showError('Erro ao inicializar dashboard');
            }
        },

        validateEnvironment() {
            if (typeof pmnDashboard === 'undefined') {
                throw new Error('pmnDashboard não está definido');
            }
        },

        bindEvents() {
            // Debounced refresh
            $(document).on('click.pmn-dashboard', '#pmn-refresh-data', 
                this.debounce(() => this.refreshData(), 1000)
            );

            // Throttled resize
            $(window).on('resize.pmn-dashboard', 
                this.throttle(() => this.resizeCharts(), 250)
            );

            // Lazy timeline loading
            this.setupInfiniteScroll();
        },

        /**
         * OTIMIZADO: Carrega dados com cache mais longo
         */
        async loadInitialData() {
            this.showLoading('Carregando dados...');
            
            try {
                const data = await this.fetchStats(true); // usa cache!
                await this.processInitialData(data);
            } catch (error) {
                console.error('❌ Erro ao carregar:', error);
                this.showError('Erro ao carregar dados');
            }
        },

        /**
         * OTIMIZADO: Cache de 10 minutos
         */
        async fetchStats(useCache = true) {
            const cacheKey = 'dashboard-stats';
            
            // Verifica cache
            if (useCache && this.cache.has(cacheKey)) {
                const cached = this.cache.get(cacheKey);
                if (Date.now() - cached.timestamp < this.config.cacheTimeout) {
                    console.log('📋 Cache hit! (evitou requisição)');
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
                    },
                    timeout: 15000,
                    cache: true, // permite cache do browser
                });

                if (!response.success) {
                    throw new Error(response.data?.message || 'Erro no servidor');
                }

                // Cache por 10 minutos
                this.cache.set(cacheKey, {
                    data: response.data,
                    timestamp: Date.now()
                });

                return response.data;

            } finally {
                this.config.requestQueue.delete(requestId);
            }
        },

        async processInitialData(data) {
            // Carrega em paralelo para ser mais rápido
            await Promise.all([
                this.updateMetrics(data.metrics, data.changes),
                this.initCharts(data.charts),
                this.loadTimelineLazy(), // lazy!
            ]);
            
            this.config.currentData = data;
            this.hideLoading();
        },

        /**
         * NOVO: Timeline com lazy loading
         */
        async loadTimelineLazy() {
            const container = $('#pmn-recent-timeline');
            container.html('<div class="pmn-loading-spinner">Carregando...</div>');

            try {
                const response = await $.ajax({
                    url: pmnDashboard.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'pmn_dashboard_timeline',
                        nonce: pmnDashboard.nonce,
                        limit: 5, // carrega só 5 inicialmente!
                        offset: 0
                    },
                    timeout: 5000,
                });

                if (response.success && response.data) {
                    this.renderTimeline(response.data, true);
                }
                
            } catch (error) {
                console.error('❌ Erro timeline:', error);
                container.html('<p style="color:#999">Erro ao carregar timeline</p>');
            }
        },

        /**
         * NOVO: Infinite scroll na timeline
         */
        setupInfiniteScroll() {
            const container = $('#pmn-recent-timeline');
            let offset = 0;
            let loading = false;

            container.on('scroll', this.throttle(() => {
                if (loading) return;

                const scrollTop = container.scrollTop();
                const scrollHeight = container[0].scrollHeight;
                const clientHeight = container[0].clientHeight;

                // Carrega quando chegar a 80% do fim
                if (scrollTop + clientHeight >= scrollHeight * 0.8) {
                    loading = true;
                    offset += 5;

                    this.loadMoreTimeline(offset).then(() => {
                        loading = false;
                    });
                }
            }, 300));
        },

        async loadMoreTimeline(offset) {
            try {
                const response = await $.ajax({
                    url: pmnDashboard.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'pmn_dashboard_timeline',
                        nonce: pmnDashboard.nonce,
                        limit: 5,
                        offset: offset
                    },
                });

                if (response.success && response.data?.length > 0) {
                    this.renderTimeline(response.data, false); // append
                }
            } catch (error) {
                console.error('Erro ao carregar mais:', error);
            }
        },

        renderTimeline(activities, replace = true) {
            const container = $('#pmn-recent-timeline');
            
            if (replace) {
                container.empty();
            }

            if (!activities || activities.length === 0) {
                if (replace) {
                    container.html('<p>Nenhuma atividade</p>');
                }
                return;
            }

            // Usa DocumentFragment para performance
            const fragment = document.createDocumentFragment();

            activities.forEach(activity => {
                const div = document.createElement('div');
                div.className = 'pmn-timeline-item';
                div.innerHTML = `
                    <div class="pmn-timeline-marker"></div>
                    <div class="pmn-timeline-content">
                        <strong>${this.escapeHtml(activity.numero)}</strong>
                        <p>${this.escapeHtml(activity.assunto || 'Sem assunto')}</p>
                        <span class="pmn-timeline-time">${this.getTimeAgo(activity.data)}</span>
                    </div>
                `;
                fragment.appendChild(div);
            });

            container.append(fragment);
        },

        /**
         * OTIMIZADO: Inicializa gráficos apenas quando visíveis
         */
        async initCharts(chartsData) {
            if (!window.Chart) {
                console.warn('Chart.js não carregado');
                return;
            }

            // Configuração global
            Chart.defaults.font.family = 'system-ui, sans-serif';
            Chart.defaults.responsive = true;
            Chart.defaults.maintainAspectRatio = false;

            // Usa IntersectionObserver para lazy load
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const chartId = entry.target.id;
                        
                        if (chartId === 'pmn-status-chart' && chartsData.status) {
                            this.initStatusChart(chartsData.status);
                        } else if (chartId === 'pmn-tipo-chart' && chartsData.tipos) {
                            this.initTipoChart(chartsData.tipos);
                        } else if (chartId === 'pmn-timeline-chart' && chartsData.timeline) {
                            this.initTimelineChart(chartsData.timeline);
                        }
                        
                        observer.unobserve(entry.target);
                    }
                });
            }, { rootMargin: '50px' });

            // Observa os canvas
            ['pmn-status-chart', 'pmn-tipo-chart', 'pmn-timeline-chart'].forEach(id => {
                const canvas = document.getElementById(id);
                if (canvas) observer.observe(canvas);
            });
        },

        initStatusChart(data) {
            const ctx = document.getElementById('pmn-status-chart');
            if (!ctx || this.config.charts.status) return;

            this.config.charts.status = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: data.labels || [],
                    datasets: [{
                        data: data.data || [],
                        backgroundColor: data.colors || ['#3B82F6', '#10B981', '#F59E0B', '#6B7280'],
                    }]
                },
                options: {
                    plugins: {
                        legend: { position: 'bottom' }
                    },
                    animation: { duration: 800 } // reduzido
                }
            });
        },

        updateMetrics(metrics, changes) {
            Object.keys(metrics).forEach(key => {
                const $value = $(`[data-metric="${key}"]`);
                if ($value.length) {
                    // Animação otimizada
                    this.animateNumber($value[0], 0, metrics[key], 500);
                }
            });
        },

        animateNumber(element, from, to, duration = 500) {
            const start = Date.now();
            const update = () => {
                const elapsed = Date.now() - start;
                const progress = Math.min(elapsed / duration, 1);
                const current = Math.round(from + (to - from) * progress);
                
                element.textContent = current.toLocaleString('pt-BR');
                
                if (progress < 1) {
                    requestAnimationFrame(update);
                }
            };
            update();
        },

        resizeCharts() {
            Object.values(this.config.charts).forEach(chart => {
                if (chart && typeof chart.resize === 'function') {
                    chart.resize();
                }
            });
        },

        setupAutoRefresh() {
            if (!pmnDashboard.autoRefresh) return;

            setInterval(() => {
                if (!document.hidden && !this.state.isLoading) {
                    this.refreshData(true); // silent
                }
            }, this.config.refreshInterval);
        },

        async refreshData(silent = false) {
            if (this.state.isLoading) return;
            
            this.state.isLoading = true;
            
            try {
                const data = await this.fetchStats(false); // força nova requisição
                await this.processInitialData(data);
                
                if (!silent) {
                    this.showToast('Dados atualizados', 'success');
                }
            } catch (error) {
                console.error('Erro no refresh:', error);
            } finally {
                this.state.isLoading = false;
            }
        },

        // Utilidades
        debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        },

        throttle(func, limit) {
            let inThrottle;
            return function(...args) {
                if (!inThrottle) {
                    func.apply(this, args);
                    inThrottle = true;
                    setTimeout(() => inThrottle = false, limit);
                }
            };
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        },

        getTimeAgo(dateString) {
            if (!dateString) return 'Data inválida';
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffMinutes = Math.floor(diffMs / (1000 * 60));
            
            if (diffMinutes < 1) return 'Agora';
            if (diffMinutes < 60) return `${diffMinutes} min atrás`;
            const diffHours = Math.floor(diffMinutes / 60);
            if (diffHours < 24) return `${diffHours}h atrás`;
            const diffDays = Math.floor(diffHours / 24);
            return `${diffDays} dia(s) atrás`;
        },

        showLoading(msg) {
            $('#pmn-loading-overlay').fadeIn(200).find('p').text(msg);
        },

        hideLoading() {
            $('#pmn-loading-overlay').fadeOut(200);
        },

        showError(msg) {
            this.hideLoading();
            this.showToast(msg, 'error');
        },

        showToast(msg, type = 'info') {
            $('.pmn-toast').remove();
            
            const toast = $(`
                <div class="pmn-toast pmn-toast-${type}">
                    ${msg}
                </div>
            `);
            
            $('body').append(toast);
            toast.fadeIn(200);
            
            setTimeout(() => toast.fadeOut(200, () => toast.remove()), 3000);
        },

        destroy() {
            $(document).off('.pmn-dashboard');
            $(window).off('.pmn-dashboard');
            Object.values(this.config.charts).forEach(chart => {
                if (chart && typeof chart.destroy === 'function') {
                    chart.destroy();
                }
            });
            this.cache.clear();
        }
    };

    // Auto-init
    $(document).ready(() => {
        if ($('.pmn-dashboard').length) {
            Dashboard.init();
        }
    });

    window.Dashboard = Dashboard;

})(jQuery);
