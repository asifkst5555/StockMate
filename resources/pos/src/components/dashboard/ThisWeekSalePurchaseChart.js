import React, { useEffect, useState } from "react";
import { Bar } from "react-chartjs-2";
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    Title,
    Tooltip,
    Legend,
} from "chart.js";
import { Card, NavDropdown } from "react-bootstrap";
import {
    getFormattedMessage,
    placeholderText,
    currencySymbolHandling,
    getPermission,
} from "../../shared/sharedMethod";
import { Row } from "react-bootstrap-v5";
import { connect } from "react-redux";
import { weekSalePurchases } from "../../store/action/weeksalePurchaseAction";
import { yearlyTopProduct } from "../../store/action/yearlyTopProductAction";
import moment from "moment";
import TopSellingProductChart from "./TopSellingProductChart";
import LineChart from "./LineChart";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faBars } from "@fortawesome/free-solid-svg-icons";
import { Permissions } from "../../constants";

const ThisWeekSalePurchaseChart = (props) => {
    ChartJS.register(
        CategoryScale,
        LinearScale,
        BarElement,
        Title,
        Tooltip,
        Legend
    );

    const {
        frontSetting,
        weekSalePurchases,
        weekSalePurchase,
        yearTopProduct,
        yearlyTopProduct,
        allConfigData,
    } = props;

    const [isLineChart, isSetLineChart] = useState(false);
    const year = new Date();

    useEffect(() => {
        weekSalePurchases();
        yearlyTopProduct();
    }, []);

    const currency = frontSetting
        ? frontSetting.value && frontSetting.value.currency_symbol
        : "$";

    const valueFormatter = (tooltipItems) => {
        const value = tooltipItems.dataset.data[tooltipItems.dataIndex];
        const label = tooltipItems.dataset.label;
        const currencySymbol = currency ? currency : "";
        return (
            label +
            " : " +
            currencySymbolHandling(allConfigData, currencySymbol, value, true)
        );
    };

    const yFormatter = (yValue) => {
        const value = yValue;
        const currencySymbol = currency ? currency : "";
        return currencySymbolHandling(
            allConfigData,
            currencySymbol,
            value,
            true
        );
    };

    const options = {
        responsive: true,
        maintainAspectRatio: false,
        layout: {
            padding: {
                top: 4,
                right: 8,
                bottom: 4,
                left: 4,
            },
        },
        animation: {
            duration: 1200,
            easing: "easeOutQuart",
        },
        hover: {
            mode: "nearest",
            intersect: true,
        },
        plugins: {
            legend: {
                position: "top",
                labels: {
                    padding: 16,
                    usePointStyle: true,
                    boxWidth: 10,
                },
            },
            tooltip: {
                backgroundColor: "rgba(0,0,0,0.7)",
                titleColor: "#fff",
                bodyColor: "#fff",
                callbacks: {
                    label: (tooltipItems) => valueFormatter(tooltipItems),
                },
            },
        },
        scales: {
            y: {
                ticks: {
                    callback: (value) => yFormatter(value),
                },
                title: {
                    display: true,
                    text: placeholderText("amount.title"),
                    align: "center",
                },
                beginAtZero: true,
                grid: {
                    color: "rgba(200,200,200,0.2)",
                },
            },
            x: {
                grid: {
                    display: false,
                },
            },
        },
    };

    const labels = weekSalePurchase ? weekSalePurchase.dates : "";

    const data = {
        labels,
        datasets: [
            {
                label: placeholderText("sales.title"),
                data: weekSalePurchase ? weekSalePurchase.sales : [],
                backgroundColor: (context) => {
                    const chart = context.chart;
                    const { ctx, chartArea } = chart;
                    if (!chartArea) return "rgba(244, 119, 22, 0.85)";

                    const gradientNormal = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradientNormal.addColorStop(0, "rgba(255, 138, 0, 0.95)");
                    gradientNormal.addColorStop(1, "rgba(244, 119, 22, 0.75)");

                    const gradientHover = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradientHover.addColorStop(0, "rgba(233, 104, 11, 1)");
                    gradientHover.addColorStop(1, "rgba(244, 119, 22, 0.9)");

                    return context.active ? gradientHover : gradientNormal;
                },
                borderColor: (context) =>
                    context.active ? "#E9680B" : "#F47716",
                borderWidth: 1,
                borderRadius: 5,
            },
            {
                label: placeholderText("purchases.title"),
                data: weekSalePurchase ? weekSalePurchase.purchases : [],
                backgroundColor: (context) => {
                    const chart = context.chart;
                    const { ctx, chartArea } = chart;
                    if (!chartArea) return "rgba(9, 43, 72, 0.85)";

                    const gradientNormal = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradientNormal.addColorStop(0, "rgba(18, 59, 93, 0.9)");
                    gradientNormal.addColorStop(1, "rgba(9, 43, 72, 0.75)");

                    const gradientHover = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
                    gradientHover.addColorStop(0, "rgba(9, 43, 72, 1)");
                    gradientHover.addColorStop(1, "rgba(18, 59, 93, 0.9)");

                    return context.active ? gradientHover : gradientNormal;
                },
                borderColor: (context) =>
                    context.active ? "#092B48" : "#123B5D",
                borderWidth: 1,
                borderRadius: 5,
            },
        ],
    };

    const hasTopSellingPermission = getPermission(
        allConfigData?.permissions,
        Permissions.MANAGE_SALE
    );

    return (
        <div
            className={`stockmate-dashboard-split ${
                hasTopSellingPermission ? "" : "stockmate-dashboard-split--single"
            }`}
        >
            <div className="stockmate-dashboard-split__left">
                <Card className="stockmate-sales-card">
                    <Card.Header className="stockmate-sales-card__header">
                        <h5 className="stockmate-pie-card__title">
                            {getFormattedMessage(
                                "dashboard.ThisWeekSales&Purchases.title"
                            )}
                        </h5>
                        <div className="chart-dropdown">
                            <NavDropdown
                                title={<FontAwesomeIcon icon={faBars} />}
                            >
                                <NavDropdown.Item
                                    href="#/"
                                    onClick={() => isSetLineChart(false)}
                                    className={`${
                                        isLineChart === true
                                            ? ""
                                            : "text-primary"
                                    } fs-6`}
                                >
                                    {getFormattedMessage("bar.title")}
                                </NavDropdown.Item>
                                <NavDropdown.Item
                                    href="#"
                                    className={`${
                                        isLineChart === true
                                            ? "text-primary"
                                            : ""
                                    } fs-6`}
                                    onClick={() => isSetLineChart(true)}
                                >
                                    {getFormattedMessage("line.title")}
                                </NavDropdown.Item>
                            </NavDropdown>
                        </div>
                    </Card.Header>
                    <Card.Body className="stockmate-sales-card__body">
                        <div className="stockmate-sales-card__chart">
                            {data && currency && isLineChart === false && (
                                <Bar
                                    options={options}
                                    data={data}
                                />
                            )}
                            {data && currency && isLineChart === true && (
                                <LineChart
                                    weekSalePurchase={weekSalePurchase}
                                    frontSetting={frontSetting}
                                />
                            )}
                        </div>
                    </Card.Body>
                </Card>
            </div>
            {hasTopSellingPermission && (
                <div className="stockmate-dashboard-split__right">
                    <Card className="stockmate-pie-card">
                        <Card.Header className="stockmate-pie-card__header">
                            <h5 className="stockmate-pie-card__title">
                                {getFormattedMessage(
                                    "dashboard.TopSellingProducts.title"
                                )}
                            </h5>
                            <span className="stockmate-pie-card__period">
                                {moment(year).format("YYYY")}
                            </span>
                        </Card.Header>
                        <Card.Body className="stockmate-pie-card__body">
                            <TopSellingProductChart
                                yearTopProduct={yearTopProduct}
                                frontSetting={frontSetting}
                            />
                        </Card.Body>
                    </Card>
                </div>
            )}
        </div>
    );
};

const mapStateToProps = (state) => {
    const { weekSalePurchase, yearTopProduct, allConfigData } = state;
    return { weekSalePurchase, yearTopProduct, allConfigData };
};

export default connect(mapStateToProps, {
    weekSalePurchases,
    yearlyTopProduct,
})(ThisWeekSalePurchaseChart);
