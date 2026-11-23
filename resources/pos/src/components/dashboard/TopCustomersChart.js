import React, { useEffect, useRef, useState } from 'react';
import { Card } from 'react-bootstrap';
import moment from 'moment';
import { getFormattedMessage } from '../../shared/sharedMethod';
import ReactECharts from 'echarts-for-react';
import './DashboardCharts.css';

const CHART_COLORS = ['#F47716', '#092B48', '#FDBA74', '#94A3B8', '#FF8A00'];

const TopCustomersChart = ({ frontSetting, topCustomers, allConfigData, languageCode }) => {
  const chartRef = useRef(null);
  const [viewportWidth, setViewportWidth] = useState(
    typeof window !== 'undefined' ? window.innerWidth : 1440
  );

  useEffect(() => {
    const onResize = () => setViewportWidth(window.innerWidth);
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, []);

  const month = new Date();
  const currency = frontSetting?.value?.currency_symbol || '$';

  const allAopCustomersNames = topCustomers?.name || [];
  const allAopCustomers = topCustomers?.grand_total || [];

  const allData = allAopCustomers.map((value, i) => ({
    value: Number(parseFloat(value || 0).toFixed(2)),
    formattedValue: parseFloat(value || 0).toFixed(2),
    name: allAopCustomersNames[i] || '',
    index: i,
  }));

  const totalSum = allData.reduce((acc, item) => acc + item.value, 0);
  const splitIndex = Math.ceil(allData.length / 2);
  const leftColumnItems = allData.slice(0, splitIndex);
  const rightColumnItems = allData.slice(splitIndex);

  const isMobile = viewportWidth < 768;
  const isMediumDesktop = viewportWidth >= 1200 && viewportWidth < 1400;
  const pieRadius = isMobile ? '42%' : isMediumDesktop ? '50%' : '56%';
  const labelWidth = isMobile ? 78 : isMediumDesktop ? 105 : 128;

  const formatAmount = (val) =>
    allConfigData?.is_currency_right === 'true'
      ? `${val} ${currency}`
      : `${currency} ${val}`;

  const option = {
    color: CHART_COLORS,
    tooltip: {
      trigger: 'item',
      confine: true,
      backgroundColor: '#092B48',
      borderColor: '#092B48',
      textStyle: {
        color: '#FFFFFF',
        fontSize: 12,
      },
      extraCssText: 'max-width: 240px; white-space: normal; word-break: break-word;',
      formatter: (params) => {
        const val = Number(params.value || 0).toFixed(2);
        return `<div style="font-weight:600;margin-bottom:2px;">${params.name}</div><div>${formatAmount(val)} (${params.percent}%)</div>`;
      },
    },
    legend: {
      show: false,
    },
    series: [
      {
        name: '',
        type: 'pie',
        radius: pieRadius,
        center: ['50%', '50%'],
        avoidLabelOverlap: true,
        label: {
          show: true,
          position: 'outside',
          formatter: '{b}',
          width: labelWidth,
          overflow: 'break',
          lineHeight: 15,
          fontSize: 11.5,
          color: '#334155',
        },
        labelLine: {
          show: true,
          length: isMobile ? 8 : isMediumDesktop ? 12 : 14,
          length2: isMobile ? 6 : isMediumDesktop ? 10 : 12,
          smooth: 0.15,
        },
        labelLayout: {
          moveOverlap: 'shiftY',
        },
        data: allData.map((item) => ({
          value: item.formattedValue,
          name: item.name,
        })),
        emphasis: {
          itemStyle: {
            shadowBlur: 10,
            shadowOffsetX: 0,
            shadowColor: 'rgba(0, 0, 0, 0.35)',
          },
        },
      },
    ],
  };

  const handleHighlight = (index) => {
    const instance = chartRef.current?.getEchartsInstance();
    if (instance) {
      instance.dispatchAction({ type: 'highlight', seriesIndex: 0, dataIndex: index });
      instance.dispatchAction({ type: 'showTip', seriesIndex: 0, dataIndex: index });
    }
  };

  const handleDownplay = (index) => {
    const instance = chartRef.current?.getEchartsInstance();
    if (instance) {
      instance.dispatchAction({ type: 'downplay', seriesIndex: 0, dataIndex: index });
      instance.dispatchAction({ type: 'hideTip' });
    }
  };

  const renderLegendItem = (item) => {
    const pct = totalSum > 0 ? Math.round((item.value / totalSum) * 100) : 0;
    return (
      <li
        key={`${item.name}-${item.index}`}
        className="stockmate-pie-chart__legend-item"
        title={`${item.name} — ${formatAmount(item.formattedValue)} (${pct}%)`}
        onMouseEnter={() => handleHighlight(item.index)}
        onMouseLeave={() => handleDownplay(item.index)}
      >
        <span
          className="stockmate-pie-chart__swatch"
          style={{ backgroundColor: CHART_COLORS[item.index % CHART_COLORS.length] }}
        />
        <span className="stockmate-pie-chart__label">
          {item.name}{' '}
          <span className="stockmate-pie-chart__pct">({pct}%)</span>
        </span>
      </li>
    );
  };

  return (
    <div className="stockmate-dashboard-split__right">
      <Card className="stockmate-pie-card">
        <Card.Header className="stockmate-pie-card__header">
          <h5 className="stockmate-pie-card__title">
            {getFormattedMessage('dashboard.top-customers.title')}
          </h5>
          <span className="stockmate-pie-card__period">
            {moment(month).locale(languageCode).format('MMMM')}
          </span>
        </Card.Header>
        <Card.Body className="stockmate-pie-card__body">
          {!allData.length ? (
            <div className="stockmate-pie-chart__empty">—</div>
          ) : (
            <div className="stockmate-pie-chart">
              <div className="stockmate-pie-chart__legend-grid">
                <ul className="stockmate-pie-chart__legend-col">
                  {leftColumnItems.map(renderLegendItem)}
                </ul>
                {rightColumnItems.length > 0 && (
                  <ul className="stockmate-pie-chart__legend-col stockmate-pie-chart__legend-col--second">
                    {rightColumnItems.map(renderLegendItem)}
                  </ul>
                )}
              </div>

              <div className="stockmate-pie-chart__divider" />

              <div className="stockmate-pie-chart__canvas">
                <ReactECharts
                  ref={chartRef}
                  option={option}
                  notMerge={true}
                  style={{ height: '100%', width: '100%' }}
                />
              </div>
            </div>
          )}
        </Card.Body>
      </Card>
    </div>
  );
};

export default TopCustomersChart;
