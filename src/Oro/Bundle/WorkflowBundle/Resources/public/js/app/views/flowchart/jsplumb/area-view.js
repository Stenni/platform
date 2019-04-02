define(function(require) {
    'use strict';

    var FlowchartJsPlumbAreaView;
    var _ = require('underscore');
    var Backbone = require('backbone');
    var $ = require('jquery');
    var jsPlumb = require('jsplumb');
    var JPManager = require('../../../../tools/jsplumb-manager');
    var FlowchartJsPlumbBaseView = require('./base-view');
    require('../../../../tools/jsplumb-smartline');

    FlowchartJsPlumbAreaView = FlowchartJsPlumbBaseView.extend({
        /**
         * @type {JsPlumbManager}
         */
        jsPlumbManager: null,

        jsPlumbInstance: null,

        /**
         * @type {number}
         */
        connectionWidth: 12,

        /**
         * @type {function(): Object|Object}
         */
        defaultsChartOptions: function() {
            return {
                detachable: false,
                Endpoint: ['Dot', {
                    radius: 3,
                    cssClass: 'workflow-transition-endpoint',
                    hoverClass: 'workflow-transition-endpoint-hover'
                }],
                PaintStyle: {
                    strokeStyle: '#caa37b',
                    lineWidth: 2,
                    outlineColor: 'transparent',
                    outlineWidth: 7
                },
                HoverPaintStyle: {
                    strokeStyle: '#caa37b'
                },
                EndpointStyle: {
                    fillStyle: '#dcdcdc'
                },
                EndpointHoverStyle: {
                    fillStyle: '#caa37b'
                },
                ConnectionOverlays: [
                    ['Arrow', {
                        location: 1,
                        id: 'arrow',
                        length: 10,
                        width: 8,
                        foldback: 0.7
                    }]
                ]
            };
        },

        /**
         * @inheritDoc
         */
        constructor: function FlowchartJsPlumbAreaView() {
            FlowchartJsPlumbAreaView.__super__.constructor.apply(this, arguments);
        },

        /**
         * @inheritDoc
         */
        initialize: function(options) {
            this.defaultsChartOptions = _.extend(
                _.result(this, 'defaultsChartOptions'),
                options.chartOptions || {}
            );
            this.flowchartState = options.flowchartState;
            _.extend(this, _.pick(options, 'chartHandlers'));

            FlowchartJsPlumbAreaView.__super__.initialize.apply(this, arguments);
        },

        dispose: function() {
            if (this.disposed) {
                return;
            }

            delete this.chartHandlers;

            FlowchartJsPlumbAreaView.__super__.dispose.call(this);
        },

        delegateEvents: function() {
            FlowchartJsPlumbAreaView.__super__.delegateEvents.apply(this, arguments);
            $(document).on('zoomchange' + this.eventNamespace(), _.bind(this.onZoomChange, this));
            return this;
        },

        undelegateEvents: function() {
            FlowchartJsPlumbAreaView.__super__.undelegateEvents.apply(this, arguments);
            $(document).off(this.eventNamespace());
            return this;
        },

        render: function() {
            // do nothing except connect()
            if (!this.isConnected && !this.isConnecting) {
                this.isConnecting = true;
                this.connect();
                this.isConnected = true;
                delete this.isConnecting;
            }
            return this;
        },

        connect: function() {
            var chartOptions = _.defaults({
                container: this.id()
            }, this.defaultsChartOptions);
            this.jsPlumbInstance = jsPlumb.getInstance(chartOptions);
            this.jsPlumbInstance.eventBus = Object.create(Backbone.Events);
            if (this.chartHandlers) {
                this.listenTo(this.jsPlumbInstance.eventBus, this.chartHandlers);
            }
            this.debouncedRepaintEverything = _.debounce(this.repaintEverything.bind(this), 0);
            this.jsPlumbManager = new JPManager(this.jsPlumbInstance, this.model);
            var stepWithPosition = this.model.get('steps').find(function(step) {
                var position = step.get('position');
                return _.isArray(position) && position.length === 2;
            });
            // if positions of step wasn't defined
            if (_.isUndefined(stepWithPosition)) {
                this.jsPlumbManager.organizeBlocks();
            }
        },

        repaintEverything: function() {
            if (this.isConnected) {
                this.jsPlumbInstance.repaintEverything();
            }
        },

        onZoomChange: function(event, options) {
            this.jsPlumbInstance.setZoom(options.zoom);
        }
    });

    return FlowchartJsPlumbAreaView;
});
