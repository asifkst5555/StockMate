import React, { useEffect, useState } from "react";
import { Card, Row, Table, OverlayTrigger, Tooltip } from "react-bootstrap";
import { connect, useSelector } from "react-redux";
import moment from "moment/moment";
import {
    currencySymbolHandling,
    getFormattedMessage,
    getPermission,
} from "../../shared/sharedMethod";
import { topSellingProduct } from "../../store/action/topSellingProductAction";
import { fetchTopCustomers } from "../../store/action/topCustomersAction";
import TopCustomersChart from "./TopCustomersChart";
import { Permissions, Tokens } from "../../constants";
import "moment/min/locales.min";

const TopSellingProduct = (props) => {
    const {
        topSelling,
        topSellingProduct,
        frontSetting,
        fetchTopCustomers,
        topCustomers,
        allConfigData,
    } = props;
    const [languageCode, setLanguageCode] = useState("enGB");

    const updatedLanguage = localStorage.getItem(Tokens.UPDATED_LANGUAGE);
    const { selectedLanguage } = useSelector((state) => state);
    const messages = updatedLanguage ? updatedLanguage : selectedLanguage;
    useEffect(() => {
        topSellingProduct();
        fetchTopCustomers();
    }, []);

    useEffect(() => {
        if (messages === "en") {
            setLanguageCode("enGB");
        } else if (messages === "sp") {
            setLanguageCode("es");
        } else if (messages === "gr") {
            setLanguageCode("de");
        } else if (messages === "fr") {
            setLanguageCode("fr");
        } else if (messages === "ar") {
            setLanguageCode("ar");
        } else if (messages === "tr") {
            setLanguageCode("tr");
        } else if (messages === "vi") {
            setLanguageCode("vi");
        } else if (messages === "cn") {
            setLanguageCode("zh-cn");
        }
    }, [messages]);

    const hasSalePermission = getPermission(
        allConfigData?.permissions,
        Permissions.MANAGE_SALE
    );
    const hasCustomerPermission = getPermission(
        allConfigData?.permissions,
        Permissions.MANAGE_CUSTOMERS
    );
    const isSingleColumn = !(hasSalePermission && hasCustomerPermission);

    return (
        <div className="pt-6">
            <div
                className={`stockmate-dashboard-split ${
                    isSingleColumn ? "stockmate-dashboard-split--single" : ""
                }`}
            >
                {hasSalePermission && <div className="stockmate-dashboard-split__left">
                    <Card className="stockmate-table-card">
                        <Card.Header className="stockmate-table-card__header">
                            <h5 className="stockmate-pie-card__title">
                                {getFormattedMessage(
                                    "dashboard.TopSellingProducts.title"
                                )}
                            </h5>
                            <span className="stockmate-pie-card__period">
                                {moment().locale(languageCode).format("MMMM")}
                            </span>
                        </Card.Header>
                        <Card.Body className="stockmate-table-card__body">
                            <Table responsive className="stockmate-top-products-table">
                                <thead>
                                    <tr>
                                        <th>
                                            {getFormattedMessage(
                                                "product.title"
                                            )}
                                        </th>
                                        <th className="text-center">
                                            {getFormattedMessage(
                                                "globally.detail.quantity"
                                            )}
                                        </th>
                                        <th className="text-end">
                                            {getFormattedMessage(
                                                "globally.detail.grand.total"
                                            )}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {topSelling &&
                                        topSelling.map((top, index) => {
                                            const renderTooltip = (props) => (
                                                <Tooltip
                                                    id="button-tooltip"
                                                    {...props}
                                                >
                                                    {currencySymbolHandling(
                                                        allConfigData,
                                                        frontSetting.value &&
                                                            frontSetting.value
                                                                .currency_symbol,
                                                        top.grand_total
                                                    )}
                                                </Tooltip>
                                            );
                                            return (
                                                <tr key={index}>
                                                    <td className="stockmate-top-products-table__name">
                                                        {top.name}
                                                    </td>
                                                    <td className="text-center">
                                                        <span className="stockmate-quantity-badge">
                                                            {top?.total_quantity && parseFloat(top?.total_quantity)?.toFixed(2)}{" "}
                                                            {top.sale_unit}
                                                        </span>
                                                    </td>

                                                    <td className="text-end stockmate-top-products-table__total">
                                                        <OverlayTrigger
                                                            placement="bottom"
                                                            delay={{
                                                                show: 250,
                                                                hide: 400,
                                                            }}
                                                            overlay={
                                                                renderTooltip
                                                            }
                                                        >
                                                            <span>
                                                                {currencySymbolHandling(
                                                                    allConfigData,
                                                                    frontSetting.value &&
                                                                        frontSetting
                                                                            .value
                                                                            .currency_symbol,
                                                                    top.grand_total
                                                                )}
                                                            </span>
                                                        </OverlayTrigger>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                </tbody>
                            </Table>
                        </Card.Body>
                    </Card>
                </div>}
                {hasCustomerPermission && <TopCustomersChart
                    frontSetting={frontSetting}
                    languageCode={languageCode}
                    topCustomers={topCustomers}
                    allConfigData={allConfigData}
                />}
            </div>
        </div>
    );
};

const mapStateToProps = (state) => {
    const { topSelling, topCustomers, allConfigData } = state;
    return { topSelling, topCustomers, allConfigData };
};

export default connect(mapStateToProps, {
    topSellingProduct,
    fetchTopCustomers,
})(TopSellingProduct);
