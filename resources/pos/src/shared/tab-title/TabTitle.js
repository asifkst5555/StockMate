import React from 'react';
import {Helmet} from 'react-helmet';
import {useSelector} from "react-redux";

const TabTitle = (props) => {
    const { title } = props;
    const {frontSetting} = useSelector(state => state)

    return (
        <Helmet>
            <title>{title + ' '} {frontSetting ? ` | ${frontSetting?.value?.app_name || "StockMate"}` : " | StockMate"}</title>
            <link rel="icon" type="image/png" href={frontSetting?.value?.app_favicon || "/favicon.png"} sizes="32x32" />
        </Helmet>
    )
}

export default TabTitle;
