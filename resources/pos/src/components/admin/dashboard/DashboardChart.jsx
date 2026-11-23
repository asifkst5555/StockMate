import { faBars } from '@fortawesome/free-solid-svg-icons'
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome'
import {
    BarElement,
    CategoryScale,
    Chart as ChartJS,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Title,
    Tooltip,
} from "chart.js"
import React, { useState } from 'react'
import { Card, NavDropdown } from 'react-bootstrap'
import { Bar, Line } from 'react-chartjs-2'
import { getFormattedMessage, placeholderText } from '../../../shared/sharedMethod'
import { connect, useSelector } from 'react-redux'
import Spinner from '../../../shared/components/loaders/Spinner'

const DashboardChart = ({ frontSetting, isLoading }) => {
    const adminDashboardData = useSelector((state) => state.adminDashboardData);
    const [isLineChart, isSetLineChart] = useState(false);
    ChartJS.register(
        CategoryScale,
        LinearScale,
        PointElement,
        LineElement,
        BarElement,
        Title,
        Tooltip,
        Legend
    );

    const barChartData = {
        labels: adminDashboardData.earning_chart?.labels,
        datasets: [
            {
                label: `${placeholderText("chart.earnings.title")} ( ${frontSetting?.value?.admin_default_currency_symbol ?? ""
                    } )`,
                data: adminDashboardData.earning_chart?.dataset,
                backgroundColor: (context) => {
                    const chart = context.chart;
                    const { ctx, chartArea } = chart;

                    // Fallback color until chartArea is ready
                    if (!chartArea) {
                        return "rgba(244, 119, 22, 0.85)";
                    }

                    // Normal gradient (StockMate Orange)
                    const gradientNormal = ctx.createLinearGradient(
                        0,
                        chartArea.top,
                        0,
                        chartArea.bottom,
                    );
                    gradientNormal.addColorStop(0, "rgba(255, 138, 0, 0.95)");
                    gradientNormal.addColorStop(1, "rgba(244, 119, 22, 0.78)");

                    // Hover gradient (StockMate Deep Navy)
                    const gradientHover = ctx.createLinearGradient(
                        0,
                        chartArea.top,
                        0,
                        chartArea.bottom,
                    );
                    gradientHover.addColorStop(0, "rgba(18, 59, 93, 0.95)");
                    gradientHover.addColorStop(1, "rgba(9, 43, 72, 0.85)");

                    return context.active ? gradientHover : gradientNormal;
                },
                borderColor: (context) => {
                    return context.active
                        ? "#092B48"
                        : "#F47716";
                },
                borderWidth: 1,
                borderRadius: 5,
            },
        ],
    };

    const barChartOptions = {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true,
            },
        },
    };

    return (
        isLoading || !adminDashboardData ? (
            <Spinner />
        ) : (
            <div className="row">
                <div className="col-12 mb-4">
                    <Card>
                        <Card.Header className="pb-0 px-10">
                            <h5 className="mb-0">
                                {getFormattedMessage(
                                    "dashboard.EarningChart.title"
                                )}
                            </h5>
                            <div className="mb-2 chart-dropdown">
                                <NavDropdown
                                    title={<FontAwesomeIcon icon={faBars} />}
                                >
                                    <NavDropdown.Item
                                        href="#/"
                                        onClick={() => isSetLineChart(false)}
                                        className={`${isLineChart === true
                                            ? ""
                                            : "text-primary"
                                            } fs-6`}
                                    >
                                        {getFormattedMessage("bar.title")}
                                    </NavDropdown.Item>
                                    <NavDropdown.Item
                                        href="#"
                                        className={`${isLineChart === true
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
                        <Card.Body>
                            <div>
                                {isLineChart ? (
                                    <Line options={barChartOptions} data={barChartData} height={70} />
                                ) : (
                                    <Bar options={barChartOptions} data={barChartData} height={70} />
                                )}
                            </div>
                        </Card.Body>
                    </Card>
                </div>
            </div>
        )
    )
}

const mapStateToProps = (state) => {
    const { frontSetting } = state;
    return { frontSetting };
};

export default connect(mapStateToProps)(DashboardChart);