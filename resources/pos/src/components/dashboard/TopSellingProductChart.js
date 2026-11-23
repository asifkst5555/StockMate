import React, { useEffect, useRef, useState } from 'react';
import ReactECharts from 'echarts-for-react';
import './DashboardCharts.css';

const CHART_COLORS = ['#F47716', '#092B48', '#FDBA74', '#94A3B8', '#FF8A00'];

const TopSellingProductChart = ({ yearTopProduct }) => {
  const chartRef = useRef(null);
  const [viewportWidth, setViewportWidth] = useState(
    typeof window !== 'undefined' ? window.innerWidth : 1440
  );

  useEffect(() => {
    const onResize = () => setViewportWidth(window.innerWidth);
    window.addEventListener('resize', onResize);
    return () => window.removeEventListener('resize', onResize);
  }, []);

  const allQuantity = yearTopProduct?.total_quantity || [];
  const allName = yearTopProduct?.name || [];

  const allData = allQuantity.map((value, i) => ({
    value: Number(value || 0),
    formattedValue: Number(value || 0).toFixed(2),
    name: allName[i] || '',
    index: i,
  }));

  const totalSum = allData.reduce((acc, item) => acc + item.value, 0);
  const splitIndex = Math.ceil(allData.length / 2);
  const leftColumnItems = allData.slice(0, splitIndex);
  const rightColumnItems = allData.slice(splitIndex);

  const isMobile = viewportWidth < 576;
  const isNarrowDesktopCol = viewportWidth >= 1400 && viewportWidth < 1600;
  const pieRadius = isMobile ? '40%' : isNarrowDesktopCol ? '40%' : '50%';
  const labelWidth = isMobile ? 70 : isNarrowDesktopCol ? 78 : 105;

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
        const qty = Number(params.value || 0).toFixed(2);
        return `<div style="font-weight:600;margin-bottom:2px;">${params.name}</div><div>${qty} (${params.percent}%)</div>`;
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
          fontSize: 11,
          color: '#334155',
        },
        labelLine: {
          show: true,
          length: isMobile ? 8 : 12,
          length2: isMobile ? 6 : 10,
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
        title={`${item.name} — ${item.formattedValue} (${pct}%)`}
        onMouseEnter={() => handleHighlight(item.index)}
        onMouseLeave={() => handleDownplay(item.index)}
      >
        <span
          className="stockmate-pie-chart__swatch"
          style={{ backgroundColor: CHART_COLORS[item.index % CHART_COLORS.length] }}
        />
        <span className="stockmate-pie-chart__text">
          <span className="stockmate-pie-chart__label">{item.name}</span>
          <span className="stockmate-pie-chart__meta">{pct}%</span>
        </span>
      </li>
    );
  };

  if (!allData.length) {
    return <div className="stockmate-pie-chart__empty">—</div>;
  }

  return (
    <div className="stockmate-pie-chart">
      <div className="stockmate-pie-chart__legend-grid">
        <ul className="stockmate-pie-chart__legend-col">
          {leftColumnItems.map(renderLegendItem)}
        </ul>
        {rightColumnItems.length > 0 && (
          <ul className="stockmate-pie-chart__legend-col">
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
  );
};

export default TopSellingProductChart;
