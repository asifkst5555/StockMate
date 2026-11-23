import React from 'react';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js';
import {Line} from 'react-chartjs-2';
import {connect} from 'react-redux';
import {weekSalePurchases} from '../../store/action/weeksalePurchaseAction';
import {yearlyTopProduct} from '../../store/action/yearlyTopProductAction';
import {placeholderText} from "../../shared/sharedMethod";

const LineChart = (props) => {
    const {weekSalePurchase, frontSetting} = props

    ChartJS.register(
        CategoryScale,
        LinearScale,
        PointElement,
        LineElement,
        Title,
        Tooltip,
        Legend
    );

    const currency = frontSetting ? frontSetting.value && frontSetting.value.currency_symbol : ' $';
    const valueFormatter = (tooltipItems) => {
        const value = (tooltipItems.dataset.data[tooltipItems.dataIndex])
        const label = tooltipItems.dataset.label
        return label + ' : ' + `${currency ? currency : ''} ` + value.toFixed(2)
    };

    const yFormatter = (yValue) => {
        const value = yValue
        return `${currency ? currency : ''} ` + value.toFixed(2)
    };

    const options = {
        responsive: true,
        plugins: {
            legend: {
                position: 'top',
            },
            tooltip: {
                callbacks: {
                    label: (tooltipItems, data) => valueFormatter(tooltipItems)
                }
            },
        },
        scales: {
            y: {
                ticks: {
                    callback: (value, index, values) => yFormatter(value)
                },
                title: {
                    display: true,
                    text: placeholderText("amount.title"),
                    align: 'center'
                }
            }
        },
    };

    const labels = weekSalePurchase ? weekSalePurchase.dates : '';

    const data = {
        labels,
        datasets: [
            {
                label: placeholderText("sales.title"),
                data: weekSalePurchase ? weekSalePurchase.sales : '',
                backgroundColor: (context) => {
                    const chart = context.chart;
                    const { ctx, chartArea } = chart;
                    if (!chartArea) return "rgba(244, 119, 22, 0.7)";

                    const gradientNormal = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradientNormal.addColorStop(0, "rgba(255, 138, 0, 0.4)");
                    gradientNormal.addColorStop(1, "rgba(244, 119, 22, 0.9)");

                    const gradientHover = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradientHover.addColorStop(0, "rgba(233, 104, 11, 0.5)");
                    gradientHover.addColorStop(1, "rgba(233, 104, 11, 1)");

                    return context.active ? gradientHover : gradientNormal;
                },
                borderColor: (context) =>
                    context.active ? "#E9680B" : "#F47716",
                borderWidth: 1,
                borderRadius: 5,
            },
            {
                label: placeholderText("purchases.title"),
                data: weekSalePurchase ? weekSalePurchase.purchases : '',
                backgroundColor: (context) => {
                    const chart = context.chart;
                    const { ctx, chartArea } = chart;
                    if (!chartArea) return "rgba(9, 43, 72, 0.7)";

                    const gradientNormal = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradientNormal.addColorStop(0, "rgba(18, 59, 93, 0.35)");
                    gradientNormal.addColorStop(1, "rgba(9, 43, 72, 0.9)");

                    const gradientHover = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradientHover.addColorStop(0, "rgba(9, 43, 72, 0.5)");
                    gradientHover.addColorStop(1, "rgba(9, 43, 72, 1)");

                    return context.active ? gradientHover : gradientNormal;
                },
                borderColor: (context) =>
                    context.active ? "#092B48" : "#123B5D",
                borderWidth: 1,
                borderRadius: 5,
            },
        ],
    };
    return <Line options={options} data={data} height={100}/>
}

const mapStateToProps = (state) => {
    const {weekSalePurchase, yearTopProduct} = state;
    return {weekSalePurchase, yearTopProduct}
};

export default connect(mapStateToProps, {weekSalePurchases, yearlyTopProduct})(LineChart);

